<?php

namespace Tests\Feature\Forms;

use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Forms\Enums\FormPurpose;
use App\Domain\Forms\Models\FormAnswer;
use App\Domain\Forms\Models\FormField;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Services\Models\ServiceAxis;
use App\Domain\Services\Models\ServiceRequest;
use App\Domain\Services\Models\ServiceType;
use App\Support\Forms\FieldType;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The member side of the unified form engine: what is read to render a
 * form, and where a submission's answers end up — the same for a service
 * request as for an event registration.
 */
class FormSubmissionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function member(): User
    {
        $user = User::factory()->create();
        $user->assignRole('member');
        Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $user->id]);

        return $user;
    }

    private function serviceType(): ServiceType
    {
        $axis = ServiceAxis::create([
            'slug' => 'workspace',
            'name' => ['en' => 'Workspace', 'ar' => 'مساحة'],
            'color' => '#123456',
            'order' => 0,
        ]);

        return ServiceType::create([
            'service_axis_id' => $axis->id,
            'slug' => 'maintenance',
            'name' => ['en' => 'Maintenance', 'ar' => 'صيانة'],
            'is_active' => true,
            'visibility' => 'both',
            'request_method' => 'form',
        ]);
    }

    // ── Services ────────────────────────────────────────────────────────

    public function test_a_service_request_keeps_its_uploaded_file(): void
    {
        Storage::fake('public');
        $type = $this->serviceType();
        FormField::factory()->forOwner($type, FormPurpose::Request)->ofType(FieldType::File)
            ->create(['key' => 'photo', 'validation' => ['accept' => '.pdf', 'max_kb' => 2048]]);

        Sanctum::actingAs($this->member(), ['*']);
        $this->post("/api/v1/member/services/{$type->id}/requests", [
            'fields' => ['photo' => UploadedFile::fake()->create('photo.pdf', 100, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertCreated();

        $answer = ServiceRequest::firstOrFail()->formAnswers()->firstOrFail();
        $this->assertStringStartsWith('form-submissions/', $answer->value_file);
        Storage::disk('public')->assertExists($answer->value_file);
    }

    public function test_a_service_request_refuses_an_identity_key_in_its_answers(): void
    {
        $type = $this->serviceType();
        FormField::factory()->forOwner($type, FormPurpose::Request)->create(['key' => 'details']);

        Sanctum::actingAs($this->member(), ['*']);
        $this->postJson("/api/v1/member/services/{$type->id}/requests", [
            'fields' => ['details' => 'Leaking tap', 'phone' => '0999999999'],
        ])->assertStatus(422)->assertJsonValidationErrors('fields.phone');

        $this->assertSame(0, ServiceRequest::count());
    }

    public function test_a_service_request_stores_its_answers_in_the_unified_tables(): void
    {
        $type = $this->serviceType();
        FormField::factory()->forOwner($type, FormPurpose::Request)->create(['key' => 'details']);
        $member = $this->member();

        Sanctum::actingAs($member, ['*']);
        $this->postJson("/api/v1/member/services/{$type->id}/requests", [
            'fields' => ['details' => 'Leaking tap'],
        ])->assertCreated();

        $request = ServiceRequest::firstOrFail();
        $this->assertDatabaseHas('form_submissions', [
            'subject_type' => 'service_request',
            'subject_id' => $request->id,
            'user_id' => $member->id,
        ]);
        $this->assertSame('Leaking tap', FormAnswer::firstOrFail()->resolvedValue());
    }

    public function test_the_service_form_endpoints_read_the_unified_form(): void
    {
        $type = $this->serviceType();
        FormField::factory()->forOwner($type, FormPurpose::Request)->create(['key' => 'details', 'order' => 1]);
        FormField::factory()->forOwner($type, FormPurpose::Request)->inactive()->create(['key' => 'retired']);

        Sanctum::actingAs($this->member(), ['*']);

        $this->getJson("/api/v1/member/services/{$type->id}/registration-form")
            ->assertOk()
            ->assertJsonPath('data.external_form_url', null)
            ->assertJsonPath('data.request_method', 'form')
            ->assertJsonCount(1, 'data.registration_form')
            ->assertJsonPath('data.registration_form.0.key', 'details');

        $this->getJson("/api/v1/member/services/{$type->slug}")
            ->assertOk()
            ->assertJsonCount(1, 'data.fields')
            ->assertJsonPath('data.registration_form.0.key', 'details');
    }

    public function test_a_service_without_a_form_renders_an_empty_one(): void
    {
        $type = $this->serviceType();

        Sanctum::actingAs($this->member(), ['*']);

        $this->getJson("/api/v1/member/services/{$type->id}/registration-form")
            ->assertOk()
            ->assertJsonPath('data.registration_form', []);
    }

    public function test_the_answers_read_back_on_both_sides(): void
    {
        $type = $this->serviceType();
        FormField::factory()->forOwner($type, FormPurpose::Request)
            ->create(['key' => 'details', 'label' => ['en' => 'Details', 'ar' => 'التفاصيل']]);
        $member = $this->member();

        Sanctum::actingAs($member, ['*']);
        $this->postJson("/api/v1/member/services/{$type->id}/requests", ['fields' => ['details' => 'Leaking tap']])
            ->assertCreated();
        $request = ServiceRequest::firstOrFail();

        $this->getJson("/api/v1/member/service-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.values', [['field_key' => 'details', 'label' => 'التفاصيل', 'value' => 'Leaking tap']]);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
        $this->getJson("/api/v1/admin/service-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.values', [['field_key' => 'details', 'label' => 'التفاصيل', 'value' => 'Leaking tap']]);
    }

    // ── Events ──────────────────────────────────────────────────────────

    public function test_an_event_registration_stores_its_answers_in_the_unified_tables(): void
    {
        $event = Event::factory()->create();
        FormField::factory()->forOwner($event, FormPurpose::Registration)->create(['key' => 'company']);
        $member = $this->member();

        Sanctum::actingAs($member, ['*']);
        $this->postJson("/api/v1/member/events/{$event->id}/register", ['fields' => ['company' => 'ACME']])
            ->assertCreated();

        $attendee = EventAttendee::firstOrFail();
        $this->assertDatabaseHas('form_submissions', [
            'subject_type' => 'event_attendee',
            'subject_id' => $attendee->id,
            'user_id' => $member->id,
        ]);
        $this->assertSame('ACME', $attendee->formAnswers()->firstOrFail()->resolvedValue());
    }

    public function test_an_event_form_is_enforced_even_if_the_listing_flag_is_stale(): void
    {
        // The flag is display-only now; a form with a required question is
        // validated whatever the stored copy says.
        $event = Event::factory()->create();
        FormField::factory()->forOwner($event, FormPurpose::Registration)->required()->create(['key' => 'company']);
        $event->forceFill(['has_registration_form' => false])->saveQuietly();

        Sanctum::actingAs($this->member(), ['*']);
        $this->postJson("/api/v1/member/events/{$event->id}/register", ['fields' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fields.company');
    }

    public function test_the_event_form_endpoints_read_the_unified_form(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        FormField::factory()->forOwner($event, FormPurpose::Registration)->create(['key' => 'company']);
        FormField::factory()->forOwner($event, FormPurpose::Registration)->inactive()->create(['key' => 'retired']);

        $this->getJson("/api/v1/events/{$event->id}/registration-form")
            ->assertOk()
            ->assertJsonCount(1, 'data.registration_form')
            ->assertJsonPath('data.registration_form.0.key', 'company');

        Sanctum::actingAs($this->member(), ['*']);
        $this->getJson("/api/v1/member/events/{$event->id}/registration-form")
            ->assertOk()
            ->assertJsonCount(1, 'data.registration_form')
            ->assertJsonPath('data.registration_form.0.key', 'company');
    }
}
