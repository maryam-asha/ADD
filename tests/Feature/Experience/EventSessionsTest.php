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
    private function published(array $overrides = []): Event
    {
        return Event::factory()->create(array_merge([
            'status' => 'published',
            'start_at' => now()->addWeek(),
            'end_at' => now()->addWeek()->addHours(2),
        ], $overrides));
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

    // ── What the card says about a multi-day event ───────────────────────

    /**
     * The card's `time` block took the first day's start and the LAST day's
     * end and presented them as one range. For an event running 09:00-17:00,
     * then 10:00-14:00, then 16:00-21:00 it advertised "09:00 AM - 09:00 PM",
     * which is not when the event is open on any of its days — a member
     * reading it would arrive on day two at 20:00 to a closed hall.
     */
    public function test_the_card_does_not_invent_a_single_range_across_days(): void
    {
        $event = $this->published();
        EventSession::factory()->for($event)->on('2027-05-03', '09:00', '17:00')->create();
        EventSession::factory()->for($event)->on('2027-05-04', '10:00', '14:00')->create();
        EventSession::factory()->for($event)->on('2027-05-05', '16:00', '21:00')->create();
        app(EventSchedule::class)->syncEnvelope($event);

        $card = collect($this->getJson('/api/v1/events')->assertStatus(200)->json('data'))
            ->firstWhere('id', $event->id);

        // The invented range is gone rather than merely different.
        $this->assertNotSame('09:00 AM – 09:00 PM', $card['time']['range']);
        $this->assertTrue($card['is_multi_day']);
        $this->assertSame(3, $card['session_count']);

        // The first sitting is what a card can honestly show, and it is
        // labelled as the first rather than as the whole event.
        $this->assertSame('09:00 AM', $card['time']['start']);
        $this->assertSame('05:00 PM', $card['time']['end']);
    }

    public function test_the_card_reports_the_span_of_days_not_one_date(): void
    {
        $event = $this->published();
        EventSession::factory()->for($event)->on('2027-05-03', '09:00', '17:00')->create();
        EventSession::factory()->for($event)->on('2027-05-05', '16:00', '21:00')->create();
        app(EventSchedule::class)->syncEnvelope($event);

        $card = collect($this->getJson('/api/v1/events')->assertStatus(200)->json('data'))
            ->firstWhere('id', $event->id);

        $this->assertSame('03', $card['date']['day']);
        $this->assertSame('MAY', $card['date']['month']);
        $this->assertSame('05', $card['date']['end_day']);
        $this->assertSame('MAY', $card['date']['end_month']);
    }

    /** A one-day event keeps exactly the shape every client already handles. */
    public function test_a_single_day_event_reads_as_it_always_did(): void
    {
        $event = $this->published();
        EventSession::factory()->for($event)->on('2027-05-03', '09:00', '17:00')->create();
        app(EventSchedule::class)->syncEnvelope($event);

        $card = collect($this->getJson('/api/v1/events')->assertStatus(200)->json('data'))
            ->firstWhere('id', $event->id);

        $this->assertFalse($card['is_multi_day']);
        $this->assertSame(1, $card['session_count']);
        $this->assertSame('09:00 AM – 05:00 PM', $card['time']['range']);
        $this->assertNull($card['date']['end_day']);
    }

    /** An event predating schedules has none; it must not become multi-day. */
    public function test_an_event_without_a_schedule_is_not_reported_as_multi_day(): void
    {
        $event = $this->published();

        $card = collect($this->getJson('/api/v1/events')->assertStatus(200)->json('data'))
            ->firstWhere('id', $event->id);

        $this->assertFalse($card['is_multi_day']);
        $this->assertSame(0, $card['session_count']);
        $this->assertNotNull($card['time']['range']);
    }

    // ── Every sitting, as its own object ─────────────────────────────────

    /**
     * Reported: the dashboard listing showed only the first day, because the
     * card had no `sessions` at all — which was also why `time` had to invent
     * a range spanning them.
     */
    public function test_the_card_carries_every_sitting_with_its_own_date_and_hours(): void
    {
        $event = $this->published();
        EventSession::factory()->for($event)->on('2027-06-01', '09:00', '17:00')->create();
        EventSession::factory()->for($event)->on('2027-06-02', '16:00', '21:00')->create();
        app(EventSchedule::class)->syncEnvelope($event);

        $card = collect($this->getJson('/api/v1/events')->assertStatus(200)->json('data'))
            ->firstWhere('id', $event->id);

        $this->assertCount(2, $card['sessions']);

        $this->assertSame('2027-06-01', $card['sessions'][0]['date']);
        $this->assertSame('09:00', $card['sessions'][0]['start_time']);
        $this->assertSame('17:00', $card['sessions'][0]['end_time']);
        $this->assertSame('09:00 AM – 05:00 PM', $card['sessions'][0]['range']);

        $this->assertSame('2027-06-02', $card['sessions'][1]['date']);
        $this->assertSame('16:00', $card['sessions'][1]['start_time']);
        $this->assertSame('21:00', $card['sessions'][1]['end_time']);
    }

    /**
     * Reported: entering 22:30–04:00 stored 01:00–02:30. The editor put both
     * ends on the same day, so the end landed eighteen hours before the
     * start. A sitting that runs past midnight ends on the following day and
     * says so, rather than leaving a client to infer it from two clocks.
     */
    public function test_a_sitting_that_runs_past_midnight_is_stored_and_reported_as_one(): void
    {
        $response = $this->createEvent([
            // Published, or it stays out of the public listing checked below.
            'status' => 'published',
            'sessions' => [['start_at' => '2026-09-10 22:30', 'end_at' => '2026-09-11 04:00']],
        ])->assertStatus(201);

        $event = Event::find($response->json('data.id'));
        $session = $event->sessions()->first();

        $this->assertSame(
            '2026-09-10 22:30',
            $session->start_at->setTimezone('Asia/Damascus')->format('Y-m-d H:i')
        );
        $this->assertSame(
            '2026-09-11 04:00',
            $session->end_at->setTimezone('Asia/Damascus')->format('Y-m-d H:i')
        );

        $card = collect($this->getJson('/api/v1/events')->json('data'))->firstWhere('id', $event->id);

        $this->assertTrue($card['sessions'][0]['ends_next_day']);
        $this->assertSame('22:30', $card['sessions'][0]['start_time']);
        $this->assertSame('04:00', $card['sessions'][0]['end_time']);
    }

    public function test_a_same_day_sitting_does_not_claim_to_end_tomorrow(): void
    {
        $event = $this->published();
        EventSession::factory()->for($event)->on('2027-06-01', '09:00', '17:00')->create();
        app(EventSchedule::class)->syncEnvelope($event);

        $card = collect($this->getJson('/api/v1/events')->json('data'))->firstWhere('id', $event->id);

        $this->assertFalse($card['sessions'][0]['ends_next_day']);
    }

    public function test_schedule_returns_list_of_objects_and_detects_same_time_and_continuous_dates(): void
    {
        $event = $this->published();
        // 3 consecutive days with identical hours
        EventSession::factory()->for($event)->on('2027-06-01', '09:00', '17:00')->create();
        EventSession::factory()->for($event)->on('2027-06-02', '09:00', '17:00')->create();
        EventSession::factory()->for($event)->on('2027-06-03', '09:00', '17:00')->create();
        app(EventSchedule::class)->syncEnvelope($event);

        $card = collect($this->getJson('/api/v1/events')->assertStatus(200)->json('data'))
            ->firstWhere('id', $event->id);

        $this->assertTrue($card['is_same_time']);
        $this->assertTrue($card['is_continuous']);
        $this->assertCount(3, $card['schedule']);

        $this->assertSame('2027-06-01', $card['schedule'][0]['date']);
        $this->assertSame('09:00 AM', $card['schedule'][0]['start_time']);
        $this->assertSame('05:00 PM', $card['schedule'][0]['end_time']);
        $this->assertSame(['date', 'start_time', 'end_time'], array_keys($card['schedule'][0]));

        $this->assertSame('2027-06-02', $card['schedule'][1]['date']);
        $this->assertSame('2027-06-03', $card['schedule'][2]['date']);
    }

    public function test_schedule_flags_differing_hours_and_non_continuous_dates(): void
    {
        $event = $this->published();
        // Non-consecutive days with different hours
        EventSession::factory()->for($event)->on('2027-06-01', '09:00', '17:00')->create();
        EventSession::factory()->for($event)->on('2027-06-05', '14:00', '19:00')->create();
        app(EventSchedule::class)->syncEnvelope($event);

        $card = collect($this->getJson('/api/v1/events')->assertStatus(200)->json('data'))
            ->firstWhere('id', $event->id);

        $this->assertFalse($card['is_same_time']);
        $this->assertFalse($card['is_continuous']);
        $this->assertCount(2, $card['schedule']);
    }

    public function test_member_event_omits_date_time_start_at_end_at_and_keeps_clean_schedule(): void
    {
        $event = $this->published();
        EventSession::factory()->for($event)->on('2027-06-01', '09:00', '17:00')->create();
        app(EventSchedule::class)->syncEnvelope($event);

        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->getJson('/api/v1/member/events')->assertStatus(200);
        $card = collect($response->json('data'))->firstWhere('id', $event->id);

        // date, time, start_at, end_at, sessions are completely omitted
        $this->assertArrayNotHasKey('start_at', $card);
        $this->assertArrayNotHasKey('end_at', $card);
        $this->assertArrayNotHasKey('date', $card);
        $this->assertArrayNotHasKey('time', $card);
        $this->assertArrayNotHasKey('sessions', $card);

        // schedule has strictly date, start_time, end_time
        $this->assertArrayHasKey('schedule', $card);
        $this->assertCount(1, $card['schedule']);
        $this->assertSame(['date', 'start_time', 'end_time'], array_keys($card['schedule'][0]));
        $this->assertSame('2027-06-01', $card['schedule'][0]['date']);
        $this->assertSame('09:00 AM', $card['schedule'][0]['start_time']);
        $this->assertSame('05:00 PM', $card['schedule'][0]['end_time']);

        // Flags are present
        $this->assertTrue($card['is_same_time']);
        $this->assertTrue($card['is_continuous']);

        // status returns computed_status directly ("open") and computed_status is omitted
        $this->assertSame('open', $card['status']);
        $this->assertArrayNotHasKey('computed_status', $card);
    }

    public function test_schedule_is_strictly_ordered_ascending_regardless_of_session_insertion_order(): void
    {
        $event = $this->published();
        // Insert sessions deliberately out of chronological order (middle, late, early)
        EventSession::factory()->for($event)->on('2027-06-21', '16:00', '18:30')->create();
        EventSession::factory()->for($event)->on('2027-06-24', '17:00', '19:30')->create();
        EventSession::factory()->for($event)->on('2027-06-10', '09:00', '10:30')->create();
        app(EventSchedule::class)->syncEnvelope($event);

        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->getJson('/api/v1/member/events')->assertStatus(200);
        $card = collect($response->json('data'))->firstWhere('id', $event->id);

        $this->assertCount(3, $card['schedule']);

        // First item is strictly the start date
        $this->assertSame('2027-06-10', $card['schedule'][0]['date']);
        $this->assertSame('09:00 AM', $card['schedule'][0]['start_time']);

        // Middle item
        $this->assertSame('2027-06-21', $card['schedule'][1]['date']);
        $this->assertSame('04:00 PM', $card['schedule'][1]['start_time']);

        // Last item is strictly the end date
        $this->assertSame('2027-06-24', $card['schedule'][2]['date']);
        $this->assertSame('05:00 PM', $card['schedule'][2]['start_time']);
    }
}
