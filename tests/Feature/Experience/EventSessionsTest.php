<?php

namespace Tests\Feature\Experience;

use App\Domain\Experience\Models\Event;
use App\Domain\Experience\Models\EventSession;
use App\Domain\Experience\Services\EventSchedule;
use App\Domain\Identity\Models\User;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An event runs on a schedule, not in one unbroken stretch.
 *
 * A three-day exhibition whose hours differ per day could not be expressed:
 * `events.start_at`/`end_at` is a single interval, so the admin had to either
 * lie about the hours or split the event in three and split its registrations
 * with it.
 *
 * Sessions carry the real hours. The envelope stays on `events` and stays
 * authoritative for ordering, filtering, the registration deadline and the
 * card — it is now derived from the sessions rather than typed, so every
 * existing query and the member app keep working untouched.
 */
class EventSessionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        // The district runs three hours ahead of UTC; a wall clock typed by
        // an admin means that clock here, not in UTC.
        app(SettingService::class)->set('app.timezone', 'Asia/Damascus', SettingValueType::String);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
    }

    /** @param array<string, mixed> $overrides */
    private function createEvent(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/admin/events', array_merge([
            'title' => ['en' => 'Expo', 'ar' => 'معرض'],
            'start_at' => '2026-12-01 09:00',
            'end_at' => '2026-12-01 17:00',
        ], $overrides));
    }

    // ── Different hours per day ──────────────────────────────────────────

    public function test_an_event_can_run_different_hours_on_each_day(): void
    {
        $response = $this->createEvent([
            'sessions' => [
                ['start_at' => '2026-12-01 09:00', 'end_at' => '2026-12-01 17:00'],
                ['start_at' => '2026-12-02 10:00', 'end_at' => '2026-12-02 14:00'],
                ['start_at' => '2026-12-03 16:00', 'end_at' => '2026-12-03 21:00'],
            ],
        ])->assertStatus(201);

        $event = Event::find($response->json('data.id'));

        $this->assertSame(3, $event->sessions()->count());

        $hours = $event->sessions->map(fn ($s) => [
            $s->start_at->setTimezone('Asia/Damascus')->format('Y-m-d H:i'),
            $s->end_at->setTimezone('Asia/Damascus')->format('H:i'),
        ])->all();

        $this->assertSame([
            ['2026-12-01 09:00', '17:00'],
            ['2026-12-02 10:00', '14:00'],
            ['2026-12-03 16:00', '21:00'],
        ], $hours);
    }

    /**
     * The hours the admin typed are the district's, not UTC's. Three hours
     * of drift is enough to move a 21:00 session onto the next calendar day.
     */
    public function test_session_hours_are_read_as_the_district_clock(): void
    {
        $response = $this->createEvent([
            'sessions' => [['start_at' => '2026-12-03 16:00', 'end_at' => '2026-12-03 21:00']],
        ])->assertStatus(201);

        $session = Event::find($response->json('data.id'))->sessions()->first();

        // 16:00 Damascus is 13:00 UTC.
        $this->assertSame('2026-12-03 13:00', $session->start_at->format('Y-m-d H:i'));
        $this->assertSame('2026-12-03', $session->end_at->setTimezone('Asia/Damascus')->format('Y-m-d'));
    }

    // ── The derived envelope ─────────────────────────────────────────────

    public function test_the_event_envelope_spans_its_sessions(): void
    {
        $response = $this->createEvent([
            // Deliberately out of order: the envelope is computed, not taken
            // from the first row sent.
            'sessions' => [
                ['start_at' => '2026-12-03 16:00', 'end_at' => '2026-12-03 21:00'],
                ['start_at' => '2026-12-01 09:00', 'end_at' => '2026-12-01 17:00'],
            ],
        ])->assertStatus(201);

        $event = Event::find($response->json('data.id'));

        $this->assertSame('2026-12-01 09:00', $event->start_at->setTimezone('Asia/Damascus')->format('Y-m-d H:i'));
        $this->assertSame('2026-12-03 21:00', $event->end_at->setTimezone('Asia/Damascus')->format('Y-m-d H:i'));
    }

    public function test_editing_the_schedule_moves_the_envelope_with_it(): void
    {
        $event = Event::factory()->create();
        EventSession::factory()->for($event)->on('2026-12-01', '09:00', '17:00')->create();

        $this->patchJson("/api/v1/admin/events/{$event->id}", [
            'sessions' => [
                ['start_at' => '2026-12-05 08:00', 'end_at' => '2026-12-05 12:00'],
                ['start_at' => '2026-12-06 08:00', 'end_at' => '2026-12-06 20:00'],
            ],
        ])->assertStatus(200);

        $event->refresh();

        $this->assertSame(2, $event->sessions()->count());
        $this->assertSame('2026-12-05 08:00', $event->start_at->setTimezone('Asia/Damascus')->format('Y-m-d H:i'));
        $this->assertSame('2026-12-06 20:00', $event->end_at->setTimezone('Asia/Damascus')->format('Y-m-d H:i'));
    }

    /**
     * Ordering, the upcoming/past filters and the registration deadline all
     * read the envelope. Deriving it is what lets them stay untouched.
     */
    public function test_an_event_with_sessions_still_sorts_and_filters_by_its_envelope(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        EventSession::factory()->for($event)->on('2027-03-02', '10:00', '14:00')->create();
        EventSession::factory()->for($event)->on('2027-03-01', '09:00', '17:00')->create();

        app(EventSchedule::class)->syncEnvelope($event);

        $this->assertNotNull($event->fresh()->start_at);

        $ids = collect($this->getJson('/api/v1/events?filter=upcoming')->assertStatus(200)->json('data'))
            ->pluck('id');

        $this->assertTrue($ids->contains($event->id));
    }

    // ── Editing semantics ────────────────────────────────────────────────

    /** Omitting the key must not silently wipe a schedule. */
    public function test_an_update_that_does_not_mention_sessions_leaves_them_alone(): void
    {
        $event = Event::factory()->create();
        EventSession::factory()->for($event)->on('2026-12-01', '09:00', '17:00')->create();

        $this->patchJson("/api/v1/admin/events/{$event->id}", [
            'title' => ['en' => 'Renamed', 'ar' => 'مُعاد'],
        ])->assertStatus(200);

        $this->assertSame(1, $event->sessions()->count());
    }

    public function test_sending_an_empty_schedule_clears_it(): void
    {
        $event = Event::factory()->create();
        EventSession::factory()->for($event)->on('2026-12-01', '09:00', '17:00')->create();

        $this->patchJson("/api/v1/admin/events/{$event->id}", ['sessions' => []])
            ->assertStatus(200);

        $this->assertSame(0, $event->sessions()->count());
        // The envelope survives, or the event would drop out of every listing.
        $this->assertNotNull($event->fresh()->start_at);
    }

    public function test_deleting_an_event_takes_its_sessions_with_it(): void
    {
        $event = Event::factory()->create();
        EventSession::factory()->for($event)->on('2026-12-01', '09:00', '17:00')->create();

        $this->deleteJson("/api/v1/admin/events/{$event->id}")->assertSuccessful();

        $this->assertSame(0, EventSession::where('event_id', $event->id)->count());
    }

    // ── Validation ───────────────────────────────────────────────────────

    public function test_a_session_must_end_after_it_starts(): void
    {
        $this->createEvent([
            'sessions' => [['start_at' => '2026-12-01 17:00', 'end_at' => '2026-12-01 09:00']],
        ])->assertStatus(422)->assertJsonValidationErrors('sessions.0.end_at');
    }

    public function test_a_session_needs_both_of_its_hours(): void
    {
        $this->createEvent([
            'sessions' => [['start_at' => '2026-12-01 09:00']],
        ])->assertStatus(422)->assertJsonValidationErrors('sessions.0.end_at');
    }

    // ── The member-facing shape ──────────────────────────────────────────

    public function test_a_member_is_shown_each_sitting_with_its_own_hours(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        EventSession::factory()->for($event)->on('2026-12-01', '09:00', '17:00')->create();
        EventSession::factory()->for($event)->on('2026-12-02', '10:00', '14:00')->create();

        $sessions = $this->getJson("/api/v1/events/{$event->id}")
            ->assertStatus(200)
            ->json('data.sessions');

        $this->assertCount(2, $sessions);
        $this->assertSame('09:00', $sessions[0]['start_time']);
        $this->assertSame('17:00', $sessions[0]['end_time']);
        $this->assertSame('10:00', $sessions[1]['start_time']);
        $this->assertSame('14:00', $sessions[1]['end_time']);
    }

    /** A single-sitting event keeps the shape every client already handles. */
    public function test_an_event_without_a_schedule_still_reports_its_own_hours(): void
    {
        $event = Event::factory()->create(['status' => 'published']);

        $payload = $this->getJson("/api/v1/events/{$event->id}")->assertStatus(200)->json('data');

        // The envelope is still there, and the schedule is simply empty —
        // a client that never learns about sessions is unaffected.
        $this->assertNotNull($payload['start_at']);
        $this->assertSame([], $payload['sessions']);
    }

    // ── The schedule is how an event states its times ────────────────────

    /**
     * The admin form no longer offers a start and an end: it offers a
     * schedule, of which a one-day event is simply one row. So a create
     * carrying only sessions has to work, and has to derive the envelope
     * that every listing still reads.
     */
    public function test_an_event_can_be_created_from_its_schedule_alone(): void
    {
        $response = $this->postJson('/api/v1/admin/events', [
            'title' => ['en' => 'Expo', 'ar' => 'معرض'],
            'sessions' => [
                ['start_at' => '2026-12-01 09:00', 'end_at' => '2026-12-01 17:00'],
                ['start_at' => '2026-12-02 10:00', 'end_at' => '2026-12-02 14:00'],
            ],
        ])->assertStatus(201);

        $event = Event::find($response->json('data.id'));

        $this->assertSame(2, $event->sessions()->count());
        $this->assertSame('2026-12-01 09:00', $event->start_at->setTimezone('Asia/Damascus')->format('Y-m-d H:i'));
        $this->assertSame('2026-12-02 14:00', $event->end_at->setTimezone('Asia/Damascus')->format('Y-m-d H:i'));
    }

    /** An event still has to say when it happens, one way or the other. */
    public function test_an_event_with_neither_a_schedule_nor_an_envelope_is_refused(): void
    {
        $this->postJson('/api/v1/admin/events', [
            'title' => ['en' => 'Expo', 'ar' => 'معرض'],
        ])->assertStatus(422)->assertJsonValidationErrors('start_at');
    }
}
