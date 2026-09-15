<?php

namespace Tests\Feature\Experience;

use App\Domain\Experience\Models\Event;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Times are stored as UTC instants and shown as local wall clock.
 *
 * The failure this guards against is not a wrong hour but a wrong *day*:
 * an event entered at 23:30 local is 20:30 UTC, and rendering that stored
 * value without converting back moves the event — and its weekday, and its
 * place in the upcoming/past ordering — to the day before or after.
 */
class EventTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        // Asia/Damascus is UTC+3: far enough from UTC to roll a late
        // evening over the date boundary.
        app(SettingService::class)->set('app.timezone', 'Asia/Damascus', SettingValueType::String);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function member(): User
    {
        $user = User::factory()->create();
        $user->assignRole('member');
        Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $user->id]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createEvent(string $start, string $end): Event
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $response = $this->postJson('/api/v1/admin/events', [
            'title' => ['en' => 'Evening session', 'ar' => 'جلسة مسائية'],
            'start_at' => $start,
            'end_at' => $end,
            'status' => 'published',
        ]);

        $response->assertCreated();

        return Event::findOrFail($response->json('data.id'));
    }

    public function test_a_time_entered_by_an_admin_is_stored_as_the_utc_instant_it_means(): void
    {
        $event = $this->createEvent('2026-10-01 19:00:00', '2026-10-01 21:00:00');

        // 19:00 in Damascus is 16:00 UTC.
        $this->assertSame('2026-10-01 16:00:00', $event->start_at->utc()->toDateTimeString());
        $this->assertSame('2026-10-01 18:00:00', $event->end_at->utc()->toDateTimeString());
    }

    /** The whole point: a late evening must not become the next day. */
    public function test_a_late_evening_event_keeps_its_own_date_when_read_back(): void
    {
        $event = $this->createEvent('2026-10-01 23:30:00', '2026-10-02 01:00:00');

        // Stored as the previous day in UTC...
        $this->assertSame('2026-10-01 20:30:00', $event->start_at->utc()->toDateTimeString());

        // ...but every member-facing reading is the local day the admin meant.
        Sanctum::actingAs($this->member(), ['*']);
        $card = collect($this->getJson('/api/v1/events')->json('data'))
            ->firstWhere('id', $event->id);

        $this->assertSame('2026-10-01', $card['start_at']);
        $this->assertSame('11:30 PM', $card['time']['start']);
        $this->assertSame('01', $card['date']['day']);
        $this->assertSame('OCT', $card['date']['month']);
    }

    /** An early-morning event is the mirror case: UTC would show the day before. */
    public function test_an_early_morning_event_keeps_its_own_date(): void
    {
        $event = $this->createEvent('2026-10-02 01:30:00', '2026-10-02 03:00:00');

        // 01:30 Damascus is 22:30 UTC on the *previous* day.
        $this->assertSame('2026-10-01 22:30:00', $event->start_at->utc()->toDateTimeString());

        Sanctum::actingAs($this->member(), ['*']);
        $card = collect($this->getJson('/api/v1/events')->json('data'))
            ->firstWhere('id', $event->id);

        $this->assertSame('2026-10-02', $card['start_at']);
        $this->assertSame('01:30 AM', $card['time']['start']);
    }

    public function test_the_weekday_label_follows_the_local_date(): void
    {
        // 2026-10-01 is a Thursday; 20:30 UTC that day is still Thursday
        // locally, but a naive reading of a 23:30 local time could land on
        // Friday.
        $event = $this->createEvent('2026-10-01 23:30:00', '2026-10-02 01:00:00');

        Sanctum::actingAs($this->member(), ['*']);
        $card = collect($this->getJson('/api/v1/events')->json('data'))
            ->firstWhere('id', $event->id);

        $localWeekday = strtoupper($event->start_at->copy()->setTimezone('Asia/Damascus')->format('D'));
        $this->assertSame($localWeekday, $card['date']['weekday']);
    }

    public function test_a_client_that_states_its_own_offset_is_taken_at_its_word(): void
    {
        // An explicit +03:00 means the client already did the conversion;
        // re-interpreting it would shift the event by three more hours.
        $event = $this->createEvent('2026-10-01T19:00:00+03:00', '2026-10-01T21:00:00+03:00');

        $this->assertSame('2026-10-01 16:00:00', $event->start_at->utc()->toDateTimeString());
    }

    public function test_a_utc_instant_from_a_client_is_not_shifted_again(): void
    {
        $event = $this->createEvent('2026-10-01T16:00:00Z', '2026-10-01T18:00:00Z');

        $this->assertSame('2026-10-01 16:00:00', $event->start_at->utc()->toDateTimeString());
    }

    public function test_the_admin_resource_states_the_offset_so_a_round_trip_is_lossless(): void
    {
        $event = $this->createEvent('2026-10-01 23:30:00', '2026-10-02 01:00:00');

        Sanctum::actingAs($this->admin(), ['*']);
        $response = $this->getJson("/api/v1/admin/events/{$event->id}");

        $response->assertOk();
        // A true instant, plus the local wall clock the admin typed.
        $this->assertSame('2026-10-01T20:30:00.000000Z', $response->json('data.start_at'));
        $this->assertSame('23:30', $response->json('data.start_time'));
        $this->assertSame('Asia/Damascus', $response->json('data.timezone'));
    }

    public function test_changing_the_district_timezone_moves_the_displayed_clock_not_the_instant(): void
    {
        $event = $this->createEvent('2026-10-01 19:00:00', '2026-10-01 21:00:00');
        $storedInstant = $event->start_at->utc()->toDateTimeString();

        app(SettingService::class)->set('app.timezone', 'UTC', SettingValueType::String);

        Sanctum::actingAs($this->member(), ['*']);
        $card = collect($this->getJson('/api/v1/events')->json('data'))
            ->firstWhere('id', $event->id);

        // Same moment in time, told in a different timezone.
        $this->assertSame($storedInstant, $event->fresh()->start_at->utc()->toDateTimeString());
        $this->assertSame('04:00 PM', $card['time']['start']);
    }

    public function test_an_events_past_state_is_judged_on_the_instant_not_the_clock(): void
    {
        // Ends two hours ago in real time, whatever the display timezone.
        $event = Event::factory()->create([
            'start_at' => now()->subHours(4),
            'end_at' => now()->subHours(2),
        ]);

        Sanctum::actingAs($this->member(), ['*']);
        $this->postJson("/api/v1/member/events/{$event->id}/register")
            ->assertStatus(422)
            ->assertJsonValidationErrors('event');
    }
}
