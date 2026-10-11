<?php

namespace Tests\Feature\Experience;

use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Forms\Enums\FormPurpose;
use App\Domain\Forms\Models\FormField;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use App\Support\Forms\FieldType;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventRegistrationFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function member(): User
    {
        $user = User::factory()->create([
            'name' => 'Real Member',
            'email' => 'real@example.com',
            'phone' => '0912345678',
        ]);
        $user->assignRole('member');
        Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $user->id]);

        return $user;
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function postField(Event $event, array $overrides = []): TestResponse
    {
        return $this->postJson("/api/v1/admin/events/{$event->id}/registration-fields", array_merge([
            'key' => 'dietary_needs',
            'label' => ['en' => 'Dietary needs', 'ar' => 'احتياجات غذائية'],
            'type' => 'text',
        ], $overrides));
    }

    // ── Reserved identity keys ───────────────────────────────────────────────

    public function test_identity_keys_cannot_be_defined_as_form_fields(): void
    {
        $event = Event::factory()->create();
        Sanctum::actingAs($this->admin(), ['*']);

        foreach (['name', 'phone', 'email', 'Name', 'EMAIL'] as $key) {
            $this->postField($event, ['key' => $key])
                ->assertStatus(422)
                ->assertJsonValidationErrors('key');
        }

        $this->assertSame(0, FormField::count());
    }

    public function test_a_key_that_would_break_rule_paths_is_refused(): void
    {
        $event = Event::factory()->create();
        Sanctum::actingAs($this->admin(), ['*']);

        foreach (['a.b', 'Has Space', '1leading', 'UPPER'] as $key) {
            $this->postField($event, ['key' => $key])
                ->assertStatus(422)
                ->assertJsonValidationErrors('key');
        }
    }

    public function test_a_select_field_must_come_with_choices(): void
    {
        $event = Event::factory()->create();
        Sanctum::actingAs($this->admin(), ['*']);

        $this->postField($event, ['key' => 'size', 'type' => 'select'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('options');
    }

    public function test_creating_a_field_marks_the_event_as_having_a_form(): void
    {
        $event = Event::factory()->create();
        $this->assertFalse($event->has_registration_form);

        Sanctum::actingAs($this->admin(), ['*']);
        $this->postField($event)->assertCreated();

        $this->assertTrue($event->fresh()->has_registration_form);
    }

    public function test_a_field_of_another_event_cannot_be_edited_through_this_one(): void
    {
        $eventA = Event::factory()->create();
        $eventB = Event::factory()->create();
        $field = FormField::factory()->forOwner($eventB, FormPurpose::Registration)->create();

        Sanctum::actingAs($this->admin(), ['*']);
        $this->deleteJson("/api/v1/admin/events/{$eventA->id}/registration-fields/{$field->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('form_fields', ['id' => $field->id]);
    }

    // ── Fetching the form ────────────────────────────────────────────────────

    public function test_a_member_gets_the_registration_form_questions(): void
    {
        $event = Event::factory()->withForm()->create();
        FormField::factory()->forOwner($event, FormPurpose::Registration)->create(['key' => 'company']);
        FormField::factory()->forOwner($event, FormPurpose::Registration)->inactive()->create(['key' => 'retired']);

        $user = $this->member();
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson("/api/v1/member/events/{$event->id}/registration-form");
        $response->assertOk();

        // Inactive fields are never rendered.
        $keys = collect($response->json('data.registration_form'))->pluck('key');
        $this->assertContains('company', $keys);
        $this->assertNotContains('retired', $keys);
        $this->assertNull($response->json('data.identity'));
    }

    public function test_the_public_form_carries_registration_form(): void
    {
        $event = Event::factory()->withForm()->create();
        FormField::factory()->forOwner($event, FormPurpose::Registration)->create(['key' => 'company']);

        $response = $this->getJson("/api/v1/events/{$event->id}/registration-form");

        $response->assertOk();
        $this->assertNull($response->json('data.identity'));
        $this->assertCount(1, $response->json('data.registration_form'));
    }

    public function test_an_event_without_a_form_returns_no_registration_form(): void
    {
        $event = Event::factory()->create();

        Sanctum::actingAs($this->member(), ['*']);
        $response = $this->getJson("/api/v1/member/events/{$event->id}/registration-form");

        $response->assertOk();
        $this->assertSame(['external_form_url' => null], $response->json('data'));
    }

    public function test_an_event_with_external_form_returns_no_fields(): void
    {
        $event = Event::factory()->create([
            'external_form_url' => 'https://forms.example.com/external',
            'status' => 'published',
        ]);

        Sanctum::actingAs($this->member(), ['*']);
        $response = $this->getJson("/api/v1/member/events/{$event->id}/registration-form");

        $response->assertOk();
        // The link and nothing else.
        $this->assertSame(['external_form_url' => 'https://forms.example.com/external'], $response->json('data'));
    }

    // ── Submitting the form ──────────────────────────────────────────────────

    /** The assertion that proves validation actually runs now. */
    public function test_a_missing_required_answer_is_refused(): void
    {
        $event = Event::factory()->withForm()->create();
        FormField::factory()->forOwner($event, FormPurpose::Registration)->required()->create([
            'key' => 'company',
        ]);

        Sanctum::actingAs($this->member(), ['*']);
        $this->postJson("/api/v1/member/events/{$event->id}/register", ['fields' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fields.company');

        $this->assertSame(0, EventAttendee::count());
    }

    public function test_a_select_answer_outside_its_choices_is_refused(): void
    {
        $event = Event::factory()->withForm()->create();
        FormField::factory()->forOwner($event, FormPurpose::Registration)->select(['small', 'large'])->create([
            'key' => 'size',
        ]);

        Sanctum::actingAs($this->member(), ['*']);
        $this->postJson("/api/v1/member/events/{$event->id}/register", [
            'fields' => ['size' => 'enormous'],
        ])->assertStatus(422)->assertJsonValidationErrors('fields.size');
    }

    /** Regression: the constraint used to sit on the array, not its elements. */
    public function test_a_checkbox_answer_with_an_invalid_element_is_refused(): void
    {
        $event = Event::factory()->withForm()->create();
        FormField::factory()->forOwner($event, FormPurpose::Registration)->checkbox(['ai', 'iot'])->create([
            'key' => 'topics',
        ]);

        Sanctum::actingAs($this->member(), ['*']);
        $this->postJson("/api/v1/member/events/{$event->id}/register", [
            'fields' => ['topics' => ['ai', 'quantum']],
        ])->assertStatus(422);
    }

    public function test_submitting_an_identity_key_is_refused_and_never_overwrites_the_account(): void
    {
        $event = Event::factory()->withForm()->create();
        FormField::factory()->forOwner($event, FormPurpose::Registration)->create(['key' => 'company']);

        $user = $this->member();
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/v1/member/events/{$event->id}/register", [
            'fields' => ['company' => 'ACME', 'name' => 'Impostor'],
        ])->assertStatus(422)->assertJsonValidationErrors('fields.name');

        $this->assertSame(0, EventAttendee::count());

        // The clean submission takes the name from the account.
        $this->postJson("/api/v1/member/events/{$event->id}/register", [
            'fields' => ['company' => 'ACME'],
        ])->assertCreated();

        $this->assertDatabaseHas('event_attendees', [
            'user_id' => $user->id,
            'name' => 'Real Member',
        ]);
    }

    public function test_answers_are_stored_in_the_column_their_type_calls_for(): void
    {
        $event = Event::factory()->withForm()->create();

        $text = FormField::factory()->forOwner($event, FormPurpose::Registration)->create(['key' => 'company']);
        $select = FormField::factory()->forOwner($event, FormPurpose::Registration)->select(['s', 'm'])->create(['key' => 'size']);
        $rating = FormField::factory()->forOwner($event, FormPurpose::Registration)->ofType(FieldType::Rating)->create(['key' => 'stars']);

        Sanctum::actingAs($this->member(), ['*']);
        $this->postJson("/api/v1/member/events/{$event->id}/register", [
            'fields' => ['company' => 'ACME', 'size' => 'm', 'stars' => 4],
        ])->assertCreated();

        $attendee = EventAttendee::firstOrFail();
        $values = $attendee->formAnswers()->with('field')->get()->keyBy(fn ($v) => $v->field->key);

        $this->assertSame('ACME', $values['company']->value_text);
        $this->assertNull($values['company']->value_json);

        // Select and rating are scalar but stored as JSON, by design.
        $this->assertSame('m', $values['size']->resolvedValue());
        $this->assertSame(4, $values['stars']->resolvedValue());

        $this->assertSame($text->id, $values['company']->form_field_id);
        $this->assertSame($select->id, $values['size']->form_field_id);
        $this->assertSame($rating->id, $values['stars']->form_field_id);
    }

    public function test_an_uploaded_answer_is_stored_and_referenced_by_path(): void
    {
        Storage::fake('public');

        $event = Event::factory()->withForm()->create();
        FormField::factory()->forOwner($event, FormPurpose::Registration)->ofType(FieldType::File)->create([
            'key' => 'cv',
            'validation' => ['accept' => '.pdf', 'max_kb' => 2048],
        ]);

        Sanctum::actingAs($this->member(), ['*']);
        $this->postJson("/api/v1/member/events/{$event->id}/register", [
            'fields' => ['cv' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')],
        ])->assertCreated();

        $value = EventAttendee::firstOrFail()->formAnswers()->firstOrFail();

        $this->assertNotNull($value->value_file);
        $this->assertNull($value->value_text);
        Storage::disk('public')->assertExists($value->value_file);
    }

    public function test_an_oversized_upload_is_refused(): void
    {
        Storage::fake('public');

        $event = Event::factory()->withForm()->create();
        FormField::factory()->forOwner($event, FormPurpose::Registration)->ofType(FieldType::File)->create([
            'key' => 'cv',
            'validation' => ['max_kb' => 10],
        ]);

        Sanctum::actingAs($this->member(), ['*']);
        $this->postJson("/api/v1/member/events/{$event->id}/register", [
            'fields' => ['cv' => UploadedFile::fake()->create('cv.pdf', 5000)],
        ])->assertStatus(422)->assertJsonValidationErrors('fields.cv');

        $this->assertSame(0, EventAttendee::count());
    }

    public function test_an_event_without_a_form_still_accepts_an_empty_body(): void
    {
        $event = Event::factory()->create();

        Sanctum::actingAs($this->member(), ['*']);
        $this->postJson("/api/v1/member/events/{$event->id}/register")->assertCreated();
    }

    public function test_the_admin_roster_shows_what_each_member_answered(): void
    {
        $event = Event::factory()->withForm()->create();
        FormField::factory()->forOwner($event, FormPurpose::Registration)->create(['key' => 'company']);

        Sanctum::actingAs($this->member(), ['*']);
        $this->postJson("/api/v1/member/events/{$event->id}/register", [
            'fields' => ['company' => 'ACME'],
        ])->assertCreated();

        Sanctum::actingAs($this->admin(), ['*']);
        $response = $this->getJson("/api/v1/admin/events/{$event->id}/attendees");

        $response->assertOk();
        $row = $response->json('data.0');
        $this->assertSame('Real Member', $row['name']);
        $this->assertSame('company', $row['answers'][0]['field_key']);
        $this->assertSame('ACME', $row['answers'][0]['value']);
    }

    public function test_a_reserved_key_cannot_be_forced_in_below_the_request_layer(): void
    {
        $event = Event::factory()->create();

        $this->expectException(\RuntimeException::class);

        FormField::factory()->forOwner($event, FormPurpose::Registration)->create(['key' => 'email']);
    }

    // ── Constraints reach the member, and name themselves ─────────────────

    public function test_a_text_answer_over_its_character_limit_is_refused(): void
    {
        $event = Event::factory()->create(['has_registration_form' => true]);
        FormField::factory()->forOwner($event, FormPurpose::Registration)->create([
            'key' => 'bio', 'type' => FieldType::Text, 'validation' => ['max' => 10],
        ]);

        Sanctum::actingAs($this->member(), ['*']);

        $this->postJson("/api/v1/member/events/{$event->id}/register", [
            'fields' => ['bio' => str_repeat('a', 50)],
        ])->assertStatus(422)->assertJsonValidationErrors('fields.bio');
    }

    public function test_a_date_answer_outside_the_allowed_range_is_refused(): void
    {
        $event = Event::factory()->create(['has_registration_form' => true]);
        FormField::factory()->forOwner($event, FormPurpose::Registration)->create([
            'key' => 'arrival',
            'type' => FieldType::Date,
            'validation' => ['min_date' => '2026-06-01', 'max_date' => '2026-06-30'],
        ]);

        Sanctum::actingAs($this->member(), ['*']);
        $url = "/api/v1/member/events/{$event->id}/register";

        $this->postJson($url, ['fields' => ['arrival' => '2026-05-31']])
            ->assertStatus(422)->assertJsonValidationErrors('fields.arrival');

        $this->postJson($url, ['fields' => ['arrival' => '2026-07-01']])
            ->assertStatus(422)->assertJsonValidationErrors('fields.arrival');
    }

    public function test_a_date_answer_inside_the_range_is_accepted(): void
    {
        $event = Event::factory()->create(['has_registration_form' => true]);
        FormField::factory()->forOwner($event, FormPurpose::Registration)->create([
            'key' => 'arrival',
            'type' => FieldType::Date,
            'validation' => ['min_date' => '2026-06-01', 'max_date' => '2026-06-30'],
        ]);

        Sanctum::actingAs($this->member(), ['*']);

        $this->postJson("/api/v1/member/events/{$event->id}/register", [
            'fields' => ['arrival' => '2026-06-15'],
        ])->assertStatus(201);
    }

    public function test_a_checkbox_under_its_minimum_selection_count_is_refused(): void
    {
        $event = Event::factory()->create(['has_registration_form' => true]);
        FormField::factory()->forOwner($event, FormPurpose::Registration)->create([
            'key' => 'topics',
            'type' => FieldType::Checkbox,
            'options' => [
                ['value' => 'ai', 'label' => ['en' => 'AI', 'ar' => 'ذكاء']],
                ['value' => 'iot', 'label' => ['en' => 'IoT', 'ar' => 'إنترنت']],
            ],
            'validation' => ['min' => 2],
        ]);

        Sanctum::actingAs($this->member(), ['*']);

        $this->postJson("/api/v1/member/events/{$event->id}/register", [
            'fields' => ['topics' => ['ai']],
        ])->assertStatus(422)->assertJsonValidationErrors('fields.topics');
    }

    /**
     * The point of the whole feature: a constraint that fires has to say
     * which question it is about, in the member's own language. This used to
     * read "fields.dietary_needs", a key the member has never seen.
     */
    public function test_a_failure_names_the_question_in_the_members_language(): void
    {
        $event = Event::factory()->create(['has_registration_form' => true]);
        FormField::factory()->forOwner($event, FormPurpose::Registration)->create([
            'key' => 'dietary_needs',
            'label' => ['en' => 'Dietary needs', 'ar' => 'الاحتياجات الغذائية'],
            'type' => FieldType::Text,
            'validation' => ['max' => 10],
        ]);

        Sanctum::actingAs($this->member(), ['*']);

        $response = $this->withHeader('lang', 'ar')->postJson(
            "/api/v1/member/events/{$event->id}/register",
            ['fields' => ['dietary_needs' => str_repeat('ا', 50)]],
        )->assertStatus(422);

        $message = $response->json('errors.fields\.dietary_needs.0')
            ?? $response->json('errors')['fields.dietary_needs'][0];

        $this->assertStringContainsString('الاحتياجات الغذائية', $message);
        $this->assertStringNotContainsString('dietary_needs', $message);
    }
}
