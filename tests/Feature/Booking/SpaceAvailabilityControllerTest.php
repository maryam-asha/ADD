<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\BusinessHourException;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SpaceAvailabilityControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        // 2026-08-17 is a Monday.
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsMember(): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        return $member;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function openSpace(array $attributes = []): Space
    {
        $space = Space::factory()->room()->create(array_merge([
            'name' => ['en' => 'Meeting Room A', 'ar' => 'غرفة اجتماعات أ'],
            'hourly_rate_points' => 100,
            'slot_granularity_minutes' => 60,
        ], $attributes));

        foreach ([DayOfWeek::Sunday, DayOfWeek::Monday, DayOfWeek::Tuesday, DayOfWeek::Wednesday, DayOfWeek::Thursday] as $day) {
            BusinessHour::factory()->create([
                'branch_id' => $space->building->branch_id,
                'day_of_week' => $day,
                'open_time' => '08:00',
                'close_time' => '20:00',
            ]);
        }

        return $space;
    }

    private function book(Space $space, int $hour, int $minutes = 60): Booking
    {
        $start = Carbon::parse('2026-08-17', 'Asia/Damascus')->setTime($hour, 0);

        return Booking::factory()->create([
            'space_id' => $space->id,
            'start_at' => $start->copy()->setTimezone('UTC'),
            'end_at' => $start->copy()->addMinutes($minutes)->setTimezone('UTC'),
        ]);
    }

    public function test_a_member_sees_a_month_of_day_states_for_a_space(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability?from=2026-08-17&to=2026-08-23");

        $response->assertOk()
            ->assertJsonPath('meta.total', 7)
            ->assertJsonPath('meta.timezone', 'Asia/Damascus')
            ->assertJsonPath('data.0.date', '2026-08-17')
            ->assertJsonPath('data.0.weekday', 'MON')
            ->assertJsonPath('data.0.month', 'AUG')
            ->assertJsonPath('data.0.state', 'available')
            ->assertJsonPath('data.0.is_selectable', true)
            ->assertJsonPath('space.title', 'Meeting Room A');
    }

    public function test_the_branch_hours_card_carries_the_weekday_range_and_a_derived_timezone_label(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability?from=2026-08-17&to=2026-08-18");

        $response->assertOk()
            ->assertJsonPath('branch_hours.hours', '8:00 AM – 8:00 PM')
            ->assertJsonPath('branch_hours.days_label', 'Sunday – Thursday')
            ->assertJsonPath('branch_hours.open_time', '08:00');

        // Derived from the zone at request time, never hardcoded — Damascus
        // observes DST, so a fixed "GMT+3" would be wrong half the year.
        $this->assertStringStartsWith('GMT+', $response->json('branch_hours.timezone_label'));
        $this->assertStringContainsString(
            $response->json('branch_hours.timezone_label'),
            $response->json('branch_hours.timezone_note')
        );
    }

    public function test_a_closing_exception_returns_a_closed_banner_carrying_its_reason(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();
        BusinessHourException::factory()->create([
            'branch_id' => $space->building->branch_id,
            'date' => '2026-08-19',
            'is_closed' => true,
            'open_time' => null,
            'close_time' => null,
            'reason' => 'Public holiday',
        ]);

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability?from=2026-08-19&to=2026-08-19");

        $response->assertOk()
            ->assertJsonPath('data.0.state', 'closed')
            ->assertJsonPath('data.0.is_selectable', false)
            ->assertJsonPath('data.0.closed_banner.reason', 'Public holiday')
            ->assertJsonPath('data.0.closed_banner.severity', 'error');

        $this->assertStringContainsString('closed on this date', $response->json('data.0.closed_banner.text'));
    }

    public function test_a_weekday_with_no_schedule_is_closed_with_no_reason_to_give(): void
    {
        // Saturday has no BusinessHour rows at all. That is closed, but
        // there is no exception row to explain it — the distinction the
        // BusinessHoursService docblock calls out.
        $this->actingAsMember();
        $space = $this->openSpace();

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability?from=2026-08-22&to=2026-08-22");

        $response->assertOk()
            ->assertJsonPath('data.0.state', 'closed')
            ->assertJsonPath('data.0.closed_banner.reason', null);
    }

    public function test_a_day_whose_every_slot_is_taken_is_full_not_closed(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();
        $this->book($space, 8, 12 * 60);

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability?from=2026-08-17&to=2026-08-17");

        $response->assertOk()
            ->assertJsonPath('data.0.state', 'full')
            ->assertJsonPath('data.0.available_slot_count', 0)
            // A full day is open, so it must not show the closed banner.
            ->assertJsonPath('data.0.closed_banner', null);

        $this->assertGreaterThan(0, $response->json('data.0.total_slot_count'));
    }

    public function test_slots_for_a_day_return_available_booked_and_past_states(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();
        $this->book($space, 10);

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17");

        $response->assertOk()
            ->assertJsonPath('state', 'available')
            ->assertJsonPath('counts.total', 12)
            ->assertJsonPath('meta.granularity_minutes', 60);

        $byLabel = collect($response->json('data'))->keyBy('label');

        $this->assertSame('booked', $byLabel['10:00 AM']['state']);
        $this->assertFalse($byLabel['10:00 AM']['is_available']);
        $this->assertSame('available', $byLabel['11:00 AM']['state']);
        $this->assertNull($byLabel['11:00 AM']['reason']);
    }

    /**
     * The whole point of one shared engine: the sentence on the greyed-out
     * chip and the sentence in the 422 are the same sentence. If these ever
     * diverge, the calendar is lying to the member about why.
     */
    public function test_a_booked_slots_reason_is_the_exact_message_the_post_endpoint_returns(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();
        $this->book($space, 14);

        $chipReason = collect(
            $this->withHeader('lang', 'en')
                ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17")
                ->json('data')
        )->firstWhere('label', '02:00 PM')['reason'];

        $postMessage = $this->withHeader('lang', 'en')
            ->postJson('/api/v1/member/bookings', [
                'space_id' => $space->id,
                'start_at' => '2026-08-17 14:00',
                'end_at' => '2026-08-17 15:00',
            ])->assertStatus(422)->json('message');

        $this->assertSame($postMessage, $chipReason);
    }

    public function test_the_quote_returns_the_configured_duration_ladder_with_end_times_and_points(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/quote?start_at=2026-08-17 10:00");

        $response->assertOk()
            ->assertJsonPath('hourly_rate_points', 100)
            ->assertJsonCount(4, 'durations')
            ->assertJsonPath('durations.0.minutes', 60)
            ->assertJsonPath('durations.0.label', '1h')
            ->assertJsonPath('durations.0.points', 100)
            ->assertJsonPath('durations.0.end_time', '11:00 AM')
            ->assertJsonPath('durations.1.label', '1.5h')
            ->assertJsonPath('durations.1.points', 150)
            // The fourth chip is 3h, matching the design — derived from
            // min+granularity it would have been 2.5h.
            ->assertJsonPath('durations.3.minutes', 180)
            ->assertJsonPath('durations.3.label', '3h');
    }

    public function test_a_duration_running_past_closing_time_is_offered_but_marked_unavailable(): void
    {
        // The app greys the chip out rather than hiding it, so the member
        // can see the option exists and why it cannot be taken.
        $this->actingAsMember();
        $space = $this->openSpace();

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/quote?start_at=2026-08-17 19:00");

        $response->assertOk()
            ->assertJsonPath('durations.0.available', true)
            ->assertJsonPath('durations.1.available', false);

        $this->assertNotNull($response->json('durations.1.reason'));
    }

    public function test_a_duration_overlapping_an_existing_booking_is_marked_unavailable(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();
        $this->book($space, 11);

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/quote?start_at=2026-08-17 10:00");

        $response->assertOk()
            ->assertJsonPath('durations.0.available', true)
            ->assertJsonPath('durations.1.available', false);
    }

    public function test_a_co_space_offers_a_slot_until_its_capacity_is_reached(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace([
            'space_type' => SpaceType::CoSpace,
            'is_lockable' => false,
            'capacity' => 2,
        ]);
        $this->book($space, 10);

        $slots = fn () => collect(
            $this->withHeader('lang', 'en')
                ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17")
                ->json('data')
        )->firstWhere('label', '10:00 AM');

        $this->assertSame('available', $slots()['state'], 'One of two desks taken is still bookable.');

        $this->book($space, 10);

        $this->assertSame('at_capacity', $slots()['state']);
    }

    public function test_the_calendar_rejects_a_range_longer_than_the_cap(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();

        $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability?from=2026-08-01&to=2026-12-31")
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');
    }

    public function test_the_availability_endpoints_are_closed_to_an_unauthenticated_caller(): void
    {
        $space = $this->openSpace();

        $this->getJson("/api/v1/member/spaces/{$space->id}/availability")->assertUnauthorized();
    }

    public function test_a_non_active_space_is_not_found(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace(['status' => OperationalStatus::Maintenance]);

        $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability?from=2026-08-17&to=2026-08-17")
            ->assertNotFound();
    }

    public function test_the_arabic_locale_returns_an_arabic_legend_and_closed_banner(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();
        BusinessHourException::factory()->create([
            'branch_id' => $space->building->branch_id,
            'date' => '2026-08-19',
            'is_closed' => true,
            'open_time' => null,
            'close_time' => null,
            'reason' => 'عطلة رسمية',
        ]);

        $response = $this->withHeader('lang', 'ar')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability?from=2026-08-19&to=2026-08-19");

        $response->assertOk()
            ->assertJsonPath('legend.0.label', 'متاح')
            ->assertJsonPath('legend.1.label', 'محجوز')
            ->assertJsonPath('data.0.closed_banner.reason', 'عطلة رسمية');

        $this->assertStringContainsString('مغلق', $response->json('data.0.closed_banner.text'));
    }

    public function test_a_space_requiring_approval_says_so_in_its_rules(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace(['requires_approval' => true]);

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability?from=2026-08-17&to=2026-08-17");

        $response->assertOk()
            ->assertJsonPath('rules.requires_approval', true)
            ->assertJsonPath('rules.max_selectable_dates', 10);

        // Two lang keys written for this screen that had no consumer until
        // now.
        $this->assertNotNull($response->json('rules.approval_notice'));
        $this->assertStringContainsString('Min. 1h', $response->json('rules.min_duration_note'));
    }
}
