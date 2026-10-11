<?php

namespace Tests\Feature\Forms;

use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Forms\Contracts\HasForms;
use App\Domain\Forms\Enums\FormPurpose;
use App\Domain\Forms\Models\FormAnswer;
use App\Domain\Forms\Models\FormField;
use App\Domain\Forms\Services\FormSubmissionService;
use App\Domain\Identity\Models\User;
use App\Domain\Services\Models\ServiceAxis;
use App\Domain\Services\Models\ServiceRequest;
use App\Domain\Services\Models\ServiceType;
use App\Support\Forms\FieldType;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The admin field editor, served by one controller for every form owner.
 * Every behavioural case runs against both owners the routes exist for, so
 * the two can no longer drift the way their four hand-written Form
 * Requests did.
 */
class AdminFormFieldApiTest extends TestCase
{
    use RefreshDatabase;

    private int $made = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /** @return array<string, array{0: string}> */
    public static function owners(): array
    {
        return ['events' => ['event'], 'services' => ['service_type']];
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    /** @return array{0: Model&HasForms, 1: string, 2: FormPurpose} owner, collection URL, purpose */
    private function owner(string $kind): array
    {
        if ($kind === 'event') {
            $event = Event::factory()->create();

            return [$event, "/api/v1/admin/events/{$event->id}/registration-fields", FormPurpose::Registration];
        }

        $n = ++$this->made;
        $axis = ServiceAxis::create([
            'slug' => "axis-{$n}",
            'name' => ['en' => 'Axis', 'ar' => 'محور'],
            'color' => '#334155',
        ]);
        $type = ServiceType::create([
            'service_axis_id' => $axis->id,
            'slug' => "type-{$n}",
            'name' => ['en' => 'Type', 'ar' => 'نوع'],
            'request_method' => 'form',
            'is_active' => true,
        ]);

        return [$type, "/api/v1/admin/service-types/{$type->id}/fields", FormPurpose::Request];
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'key' => 'notes',
            'label' => ['en' => 'Notes', 'ar' => 'ملاحظات'],
            'type' => 'text',
        ], $overrides);
    }

    // ── Read ────────────────────────────────────────────────────────────

    #[DataProvider('owners')]
    public function test_index_is_empty_before_any_field_exists(string $kind): void
    {
        $this->actingAsRole('admin');
        [, $url] = $this->owner($kind);

        $this->getJson($url)->assertOk()->assertExactJson(['data' => []]);
    }

    #[DataProvider('owners')]
    public function test_index_lists_every_field_in_order_in_the_established_shape(string $kind): void
    {
        $this->actingAsRole('admin');
        [$owner, $url, $purpose] = $this->owner($kind);
        FormField::factory()->forOwner($owner, $purpose)->inactive()->create(['key' => 'second', 'order' => 2]);
        FormField::factory()->forOwner($owner, $purpose)->select(['a'])->create(['key' => 'first', 'order' => 1]);

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.0.key', 'first')
            ->assertJsonPath('data.0.options.0', ['value' => 'a', 'label' => 'a'])
            ->assertJsonPath('data.1.key', 'second')
            ->assertJsonPath('data.1.is_active', false)
            ->assertJsonStructure(['data' => [[
                'id', 'key', 'label', 'placeholder', 'type', 'validation', 'is_required', 'is_active', 'order',
            ]]]);
    }

    // ── Create ──────────────────────────────────────────────────────────

    #[DataProvider('owners')]
    public function test_store_creates_the_field_on_the_owners_form(string $kind): void
    {
        $this->actingAsRole('admin');
        [$owner, $url, $purpose] = $this->owner($kind);

        $this->postJson($url, $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.key', 'notes')
            // Arabic is the API's default locale.
            ->assertJsonPath('data.label', 'ملاحظات');

        $this->assertDatabaseHas('forms', [
            'formable_type' => $owner->getMorphClass(),
            'formable_id' => $owner->getKey(),
            'purpose' => $purpose->value,
        ]);
        $this->assertSame(1, $owner->form($purpose)->first()->fields()->count());
    }

    #[DataProvider('owners')]
    public function test_a_duplicate_key_is_a_422_not_a_500(string $kind): void
    {
        $this->actingAsRole('admin');
        [, $url] = $this->owner($kind);
        $this->postJson($url, $this->payload())->assertCreated();

        $this->postJson($url, $this->payload())->assertStatus(422)->assertJsonValidationErrors('key');
    }

    #[DataProvider('owners')]
    public function test_the_same_key_is_free_on_another_owner(string $kind): void
    {
        $this->actingAsRole('admin');
        [, $first] = $this->owner($kind);
        [, $second] = $this->owner($kind);
        $this->postJson($first, $this->payload())->assertCreated();

        $this->postJson($second, $this->payload())->assertCreated();
    }

    #[DataProvider('owners')]
    public function test_a_choice_field_needs_at_least_one_option(string $kind): void
    {
        $this->actingAsRole('admin');
        [, $url] = $this->owner($kind);

        $this->postJson($url, $this->payload(['type' => 'select']))->assertStatus(422)->assertJsonValidationErrors('options');
        $this->postJson($url, $this->payload(['type' => 'checkbox', 'options' => []]))->assertStatus(422)->assertJsonValidationErrors('options');
    }

    #[DataProvider('owners')]
    public function test_null_flags_are_refused_rather_than_hitting_not_null_columns(string $kind): void
    {
        $this->actingAsRole('admin');
        [, $url] = $this->owner($kind);

        $this->postJson($url, $this->payload(['is_required' => null]))->assertStatus(422)->assertJsonValidationErrors('is_required');
        $this->postJson($url, $this->payload(['order' => null]))->assertStatus(422)->assertJsonValidationErrors('order');
    }

    #[DataProvider('owners')]
    public function test_a_reserved_key_is_refused(string $kind): void
    {
        $this->actingAsRole('admin');
        [, $url] = $this->owner($kind);

        $this->postJson($url, $this->payload(['key' => 'phone']))->assertStatus(422)->assertJsonValidationErrors('key');
    }

    public function test_an_event_registering_externally_accepts_no_fields(): void
    {
        $this->actingAsRole('admin');
        $event = Event::factory()->create(['external_form_url' => 'https://forms.example.com/x']);

        $this->postJson("/api/v1/admin/events/{$event->id}/registration-fields", $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('key');

        $this->assertNull($event->registrationForm()->first());
    }

    public function test_the_events_listing_flag_follows_the_api(): void
    {
        $this->actingAsRole('admin');
        $event = Event::factory()->create(['has_registration_form' => false]);
        $url = "/api/v1/admin/events/{$event->id}/registration-fields";

        $id = $this->postJson($url, $this->payload())->assertCreated()->json('data.id');
        $this->assertTrue($event->fresh()->has_registration_form);

        $this->patchJson("{$url}/{$id}", ['is_active' => false])->assertOk();
        $this->assertFalse($event->fresh()->has_registration_form);
    }

    // ── Update ──────────────────────────────────────────────────────────

    #[DataProvider('owners')]
    public function test_renaming_a_key_onto_a_sibling_is_a_422_and_keeping_it_is_fine(string $kind): void
    {
        $this->actingAsRole('admin');
        [$owner, $url, $purpose] = $this->owner($kind);
        FormField::factory()->forOwner($owner, $purpose)->create(['key' => 'taken']);
        $field = FormField::factory()->forOwner($owner, $purpose)->create(['key' => 'mine']);

        $this->patchJson("{$url}/{$field->id}", ['key' => 'taken'])->assertStatus(422)->assertJsonValidationErrors('key');
        $this->patchJson("{$url}/{$field->id}", ['key' => 'mine', 'label' => ['en' => 'Mine', 'ar' => 'لي']])
            ->assertOk()
            ->assertJsonStructure(['message']);
    }

    #[DataProvider('owners')]
    public function test_a_label_only_patch_on_a_choice_field_keeps_its_options(string $kind): void
    {
        $this->actingAsRole('admin');
        [$owner, $url, $purpose] = $this->owner($kind);
        $field = FormField::factory()->forOwner($owner, $purpose)->select(['s', 'm'])->create(['key' => 'size']);

        $this->patchJson("{$url}/{$field->id}", ['label' => ['en' => 'Size', 'ar' => 'المقاس']])->assertOk();

        $this->assertSame(['s', 'm'], array_column($field->fresh()->options, 'value'));
    }

    #[DataProvider('owners')]
    public function test_turning_a_text_field_into_a_choice_needs_options(string $kind): void
    {
        $this->actingAsRole('admin');
        [$owner, $url, $purpose] = $this->owner($kind);
        $field = FormField::factory()->forOwner($owner, $purpose)->create(['key' => 'notes']);

        $this->patchJson("{$url}/{$field->id}", ['type' => 'select'])->assertStatus(422)->assertJsonValidationErrors('options');
        $this->patchJson("{$url}/{$field->id}", ['type' => 'select', 'options' => [['value' => 'a', 'label' => ['en' => 'A', 'ar' => 'أ']]]])->assertOk();
    }

    #[DataProvider('owners')]
    public function test_a_patch_without_a_type_validates_against_the_stored_one(string $kind): void
    {
        $this->actingAsRole('admin');
        [$owner, $url, $purpose] = $this->owner($kind);
        $field = FormField::factory()->forOwner($owner, $purpose)->create(['key' => 'notes', 'validation' => ['max' => 500]]);

        $this->patchJson("{$url}/{$field->id}", ['validation' => ['max_kb' => 10]])->assertStatus(422);
        $this->patchJson("{$url}/{$field->id}", ['label' => ['en' => 'Renamed', 'ar' => 'معاد']])->assertOk();

        $this->assertSame(['max' => 500], $field->fresh()->validation);
    }

    // ── Delete ──────────────────────────────────────────────────────────

    #[DataProvider('owners')]
    public function test_deleting_an_answered_field_keeps_the_answers(string $kind): void
    {
        $this->actingAsRole('admin');
        [$owner, $url, $purpose] = $this->owner($kind);
        $field = FormField::factory()->forOwner($owner, $purpose)->create(['key' => 'notes']);
        $subject = $kind === 'event'
            ? EventAttendee::factory()->create(['event_id' => $owner->id])
            : ServiceRequest::create(['service_type_id' => $owner->id, 'user_id' => User::factory()->create()->id, 'status' => 'pending']);
        app(FormSubmissionService::class)->record($owner->form($purpose)->first(), $subject, ['notes' => 'kept']);

        $this->deleteJson("{$url}/{$field->id}")->assertOk()->assertJsonStructure(['message']);

        $this->assertNull(FormField::find($field->id));
        $answer = FormAnswer::firstOrFail();
        $this->assertSame('kept', $answer->resolvedValue());
        $this->assertSame('notes', $answer->displayKey());
    }

    // ── Scoping ─────────────────────────────────────────────────────────

    #[DataProvider('owners')]
    public function test_a_field_is_only_reachable_through_its_own_owner(string $kind): void
    {
        $this->actingAsRole('admin');
        [$owner, , $purpose] = $this->owner($kind);
        [, $otherUrl] = $this->owner($kind);
        $field = FormField::factory()->forOwner($owner, $purpose)->create();

        $this->patchJson("{$otherUrl}/{$field->id}", ['is_active' => false])->assertNotFound();
        $this->deleteJson("{$otherUrl}/{$field->id}")->assertNotFound();
        $this->assertNotNull(FormField::find($field->id));
    }

    public function test_the_same_numeric_owner_id_does_not_cross_owner_types(): void
    {
        $this->actingAsRole('admin');
        [$type] = $this->owner('service_type');
        // Two tables' sequences hand out the same ids all the time.
        $event = Event::factory()->create(['id' => $type->id]);
        $field = FormField::factory()->forOwner($type, FormPurpose::Request)->create();

        $this->deleteJson("/api/v1/admin/events/{$event->id}/registration-fields/{$field->id}")->assertNotFound();
        $this->assertNotNull(FormField::find($field->id));
    }

    public function test_a_field_on_another_purpose_of_the_same_owner_is_not_reachable(): void
    {
        $this->actingAsRole('admin');
        $event = Event::factory()->create();
        $field = FormField::factory()->forOwner($event, FormPurpose::Request)->create();

        $this->deleteJson("/api/v1/admin/events/{$event->id}/registration-fields/{$field->id}")->assertNotFound();
    }

    public function test_an_unknown_owner_is_a_404(): void
    {
        $this->actingAsRole('admin');

        $this->getJson('/api/v1/admin/events/999999/registration-fields')->assertNotFound();
        $this->getJson('/api/v1/admin/service-types/999999/fields')->assertNotFound();
    }

    // ── Guards ──────────────────────────────────────────────────────────

    public function test_operations_edit_event_forms_but_not_service_forms(): void
    {
        $this->actingAsRole('operations');
        [, $eventUrl] = $this->owner('event');
        [, $serviceUrl] = $this->owner('service_type');

        $this->getJson($eventUrl)->assertOk();
        $this->getJson($serviceUrl)->assertForbidden();
    }

    #[DataProvider('owners')]
    public function test_a_member_cannot_reach_the_editor(string $kind): void
    {
        $this->actingAsRole('member');
        [, $url] = $this->owner($kind);

        $this->getJson($url)->assertForbidden();
    }

    public function test_the_admin_service_type_detail_carries_its_active_fields(): void
    {
        $this->actingAsRole('admin');
        [$type] = $this->owner('service_type');
        FormField::factory()->forOwner($type, FormPurpose::Request)->create(['key' => 'shown']);
        FormField::factory()->forOwner($type, FormPurpose::Request)->inactive()->create(['key' => 'hidden']);

        $this->getJson("/api/v1/admin/service-types/{$type->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.fields')
            ->assertJsonPath('data.fields.0.key', 'shown')
            ->assertJsonPath('data.registration_form.0.key', 'shown');
    }

    public function test_field_types_are_stored_as_enum_values(): void
    {
        $this->actingAsRole('admin');
        [, $url] = $this->owner('event');

        $this->postJson($url, $this->payload(['key' => 'when', 'type' => 'date']))->assertCreated();

        $this->assertSame(FieldType::Date, FormField::where('key', 'when')->first()->type);
    }
}
