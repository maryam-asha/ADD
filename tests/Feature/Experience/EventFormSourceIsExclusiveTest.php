<?php

namespace Tests\Feature\Experience;

use App\Domain\Experience\Models\Event;
use App\Domain\Experience\Models\EventRegistrationField;
use App\Domain\Identity\Models\User;
use App\Support\Forms\FieldType;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An event collects registrations in exactly one place.
 *
 * Either it sends the member to someone else's form, or it asks its own
 * questions here — never both. The two are not merely redundant together:
 * a member who filled the external form is unregistered as far as this
 * system knows, so seats, approval and points all describe a population
 * the external form has never heard of. Which of the two is live would be
 * decided by whichever branch happened to run first.
 *
 * The registration endpoint already refused an externally-registered event.
 * The hole was one layer up, in configuration: nothing stopped an admin
 * from building both, and the contradiction was only discovered later by a
 * member who could not register for an event that showed questions.
 */
class EventFormSourceIsExclusiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
    }

    private function fieldUrl(Event $event): string
    {
        return "/api/v1/admin/events/{$event->id}/registration-fields";
    }

    /** @param array<string, mixed> $overrides */
    private function postField(Event $event, array $overrides = []): TestResponse
    {
        return $this->postJson($this->fieldUrl($event), array_merge([
            'key' => 'notes',
            'label' => ['en' => 'Notes', 'ar' => 'ملاحظات'],
            'type' => 'text',
        ], $overrides));
    }

    // ── Adding questions to an externally-registered event ───────────────

    public function test_an_event_sent_to_an_external_form_cannot_be_given_questions(): void
    {
        $event = Event::factory()->create([
            'external_form_url' => 'https://forms.example.com/abc',
        ]);

        $this->postField($event)
            ->assertStatus(422)
            ->assertJsonValidationErrors('key');

        $this->assertSame(0, $event->registrationFields()->count());
    }

    // ── Pointing a questioned event at an external form ──────────────────

    public function test_an_event_with_questions_cannot_be_pointed_at_an_external_form(): void
    {
        $event = Event::factory()->create();
        EventRegistrationField::factory()->for($event)->create([
            'key' => 'notes',
            'type' => FieldType::Text,
        ]);

        $this->patchJson("/api/v1/admin/events/{$event->id}", [
            'external_form_url' => 'https://forms.example.com/abc',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('external_form_url');

        $this->assertNull($event->fresh()->external_form_url);
    }

    public function test_an_event_can_be_created_with_an_external_form(): void
    {
        $this->assertNotNull(
            Event::factory()->create(['external_form_url' => 'https://forms.example.com/abc'])->external_form_url,
        );
    }

    // ── Switching from one to the other ──────────────────────────────────

    /**
     * The way out of each configuration has to exist, or an admin who picks
     * wrong is stuck with it.
     */
    public function test_clearing_the_external_form_frees_the_event_to_ask_its_own_questions(): void
    {
        $event = Event::factory()->create([
            'external_form_url' => 'https://forms.example.com/abc',
        ]);

        $this->patchJson("/api/v1/admin/events/{$event->id}", ['external_form_url' => null])
            ->assertStatus(200);

        $this->postField($event->fresh())->assertStatus(201);
    }

    public function test_removing_the_last_question_frees_the_event_for_an_external_form(): void
    {
        $event = Event::factory()->create();
        $field = EventRegistrationField::factory()->for($event)->create([
            'key' => 'notes',
            'type' => FieldType::Text,
        ]);

        $this->deleteJson("{$this->fieldUrl($event)}/{$field->id}")->assertStatus(200);

        $this->patchJson("/api/v1/admin/events/{$event->id}", [
            'external_form_url' => 'https://forms.example.com/abc',
        ])->assertStatus(200);
    }

    // ── An inactive question still counts ────────────────────────────────

    /**
     * A hidden question is still a question the admin wrote and expects to
     * bring back. Letting an external form in over the top of it would
     * silently strand it.
     */
    public function test_a_hidden_question_still_blocks_an_external_form(): void
    {
        $event = Event::factory()->create();
        EventRegistrationField::factory()->for($event)->create([
            'key' => 'notes',
            'type' => FieldType::Text,
            'is_active' => false,
        ]);

        $this->patchJson("/api/v1/admin/events/{$event->id}", [
            'external_form_url' => 'https://forms.example.com/abc',
        ])->assertStatus(422);
    }

    // ── The member-facing contract ───────────────────────────────────────

    /**
     * Whatever the configuration, the form endpoint must describe exactly one
     * way in — a client that sees both a link and a field list has to guess.
     */
    public function test_the_registration_form_endpoint_offers_one_way_in(): void
    {
        $external = Event::factory()->create([
            'external_form_url' => 'https://forms.example.com/abc',
            'status' => 'published',
        ]);

        $payload = $this->getJson("/api/v1/events/{$external->id}/registration-form")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('https://forms.example.com/abc', $payload['external_form_url']);
        $this->assertArrayNotHasKey('fields', $payload);
        $this->assertArrayNotHasKey('registration_form', $payload);
    }
}
