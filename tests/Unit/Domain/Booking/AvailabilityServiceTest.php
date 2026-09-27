<?php

namespace Tests\Unit\Domain\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\DayState;
use App\Domain\Booking\Enums\SlotState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\AvailabilityService;
use App\Domain\Booking\ValueObjects\DayAvailability;
use App\Domain\Booking\ValueObjects\SlotAvailability;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\BusinessHourException;
use App\Domain\Foundation\Models\Space;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The read side of the availability rules. Its job is to agree with
 * BookingCreationService about every window, which is why both consume the
 * same predicates — see AvailabilityService's class docblock and
 * tests/Guards/AvailabilityRulesAreCentralizedTest.
 */
class AvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private AvailabilityService $availability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->availability = app(AvailabilityService::class);
        // 2026-08-17 is a Monday.
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array{open: string, close: string}>  $periods
     */
    private function openSpace(array $attributes = [], array $periods = [['open' => '08:00', 'close' => '20:00']]): Space
    {
        $space = Space::factory()->room()->create(array_merge([
            'hourly_rate_points' => 100,
            'slot_granularity_minutes' => 60,
        ], $attributes));

        foreach ($periods as $period) {
            BusinessHour::factory()->create([
                'branch_id' => $space->building->branch_id,
                'day_of_week' => DayOfWeek::Monday,
                'open_time' => $period['open'],
                'close_time' => $period['close'],
            ]);
        }

        return $space->fresh(['building.branch']);
    }

    private function monday(): Carbon
    {
        return Carbon::parse('2026-08-17', 'Asia/Damascus');
    }

    /**
     * Book a window on the space, in local time.
     */
    private function book(Space $space, int $hour, int $durationMinutes = 60, array $attributes = []): Booking
    {
        $start = $this->monday()->copy()->setTime($hour, 0);

        return Booking::factory()->create(array_merge([
            'space_id' => $space->id,
            'start_at' => $start->copy()->setTimezone('UTC'),
            'end_at' => $start->copy()->addMinutes($durationMinutes)->setTimezone('UTC'),
        ], $attributes));
    }

    /**
     * @param  list<SlotAvailability>  $slots
     */
    private function stateAt(array $slots, int $hour): ?SlotState
    {
        foreach ($slots as $slot) {
            if ($slot->startAt->setTimezone('Asia/Damascus')->hour === $hour) {
                return $slot->state;
            }
        }

        return null;
    }

    public function test_a_closed_day_yields_no_slots_at_all(): void
    {
        // Tuesday has no schedule rows, so the branch is closed.
        $space = $this->openSpace();

        $slots = $this->availability->slotsForDay($space, $this->monday()->copy()->addDay());

        $this->assertSame([], $slots);
    }

    public function test_slots_are_generated_on_the_spaces_granularity_not_the_global_setting(): void
    {
        $space = $this->openSpace(['slot_granularity_minutes' => 30]);

        $slots = $this->availability->slotsForDay($space, $this->monday());

        // 08:00 to 20:00 at 30 minutes each.
        $this->assertCount(24, $slots);
    }

    public function test_a_slot_covered_by_a_confirmed_booking_is_marked_booked(): void
    {
        $space = $this->openSpace();
        $this->book($space, 10);

        $slots = $this->availability->slotsForDay($space, $this->monday());

        $this->assertSame(SlotState::Booked, $this->stateAt($slots, 10));
        $this->assertSame(SlotState::Available, $this->stateAt($slots, 11));
    }

    public function test_a_slot_covered_by_a_pending_booking_is_also_booked(): void
    {
        // A pending booking IS the hold on the slot — there is no separate
        // reservation mechanism (docs/decisions/booking-creation-approval-extension.md).
        $space = $this->openSpace();
        $this->book($space, 10, 60, ['status' => BookingStatus::Pending]);

        $slots = $this->availability->slotsForDay($space, $this->monday());

        $this->assertSame(SlotState::Booked, $this->stateAt($slots, 10));
    }

    public function test_a_slot_covered_by_a_cancelled_booking_is_available_again(): void
    {
        $space = $this->openSpace();
        $this->book($space, 10, 60, ['status' => BookingStatus::Cancelled]);

        $slots = $this->availability->slotsForDay($space, $this->monday());

        $this->assertSame(SlotState::Available, $this->stateAt($slots, 10));
    }

    public function test_a_two_period_day_generates_no_slots_across_the_midday_gap(): void
    {
        $space = $this->openSpace([], [
            ['open' => '08:00', 'close' => '12:00'],
            ['open' => '14:00', 'close' => '18:00'],
        ]);

        $slots = $this->availability->slotsForDay($space, $this->monday());

        // 08–12 is four slots, 14–18 another four; 12:00 and 13:00 simply
        // do not exist as slots rather than being offered and refused.
        $this->assertCount(8, $slots);
        $this->assertNull($this->stateAt($slots, 12));
        $this->assertNull($this->stateAt($slots, 13));
        $this->assertSame(SlotState::Available, $this->stateAt($slots, 14));
    }

    public function test_an_exception_fully_replaces_the_weekly_schedule_for_that_date(): void
    {
        $space = $this->openSpace();
        BusinessHourException::factory()->create([
            'branch_id' => $space->building->branch_id,
            'date' => '2026-08-17',
            'is_closed' => false,
            'open_time' => '10:00',
            'close_time' => '12:00',
        ]);

        $slots = $this->availability->slotsForDay($space, $this->monday());

        $this->assertCount(2, $slots);
        $this->assertNull($this->stateAt($slots, 9));
        $this->assertSame(SlotState::Available, $this->stateAt($slots, 10));
    }

    public function test_a_co_space_marks_a_slot_at_capacity_only_once_its_capacity_is_reached(): void
    {
        $space = $this->openSpace([
            'space_type' => SpaceType::CoSpace,
            'capacity' => 3,
            'is_lockable' => false,
        ]);

        $this->book($space, 10);
        $this->book($space, 10);

        $slots = $this->availability->slotsForDay($space, $this->monday());
        $this->assertSame(SlotState::Available, $this->stateAt($slots, 10), 'Two of three seats taken is still bookable.');

        $this->book($space, 10);

        $slots = $this->availability->slotsForDay($space->fresh(['building.branch']), $this->monday());
        $this->assertSame(SlotState::AtCapacity, $this->stateAt($slots, 10));
    }

    public function test_a_room_is_booked_at_the_very_first_overlap(): void
    {
        $space = $this->openSpace(['capacity' => 8]);
        $this->book($space, 10);

        $slots = $this->availability->slotsForDay($space, $this->monday());

        // Capacity 8 describes seats around a table, not eight concurrent
        // bookings — a room is exclusive.
        $this->assertSame(SlotState::Booked, $this->stateAt($slots, 10));
    }

    public function test_buffer_blocks_the_slot_adjacent_to_a_booking_on_a_room(): void
    {
        $space = $this->openSpace(['buffer_minutes' => 30]);
        $this->book($space, 10);

        $slots = $this->availability->slotsForDay($space, $this->monday());

        $this->assertSame(SlotState::Booked, $this->stateAt($slots, 10));
        $this->assertSame(SlotState::Buffer, $this->stateAt($slots, 11));
    }

    public function test_buffer_is_ignored_for_a_co_space_that_admits_several_bookings(): void
    {
        // Two people at two different desks need no turnover gap between them.
        $space = $this->openSpace([
            'space_type' => SpaceType::CoSpace,
            'capacity' => 5,
            'is_lockable' => false,
            'buffer_minutes' => 30,
        ]);
        $this->book($space, 10);

        $slots = $this->availability->slotsForDay($space, $this->monday());

        $this->assertSame(SlotState::Available, $this->stateAt($slots, 11));
    }

    public function test_slots_earlier_than_now_are_marked_past(): void
    {
        // Move now() to 11:30 so 08:00–11:00 are behind us and 11:00 is
        // mid-slot. A slot starting exactly at now() is NOT past — the
        // same inclusive boundary the write path uses, where a booking
        // starting this minute is still accepted.
        Carbon::setTestNow(Carbon::parse('2026-08-17 11:30:00', 'Asia/Damascus'));

        $space = $this->openSpace();

        $slots = $this->availability->slotsForDay($space, $this->monday());

        $this->assertSame(SlotState::Past, $this->stateAt($slots, 8));
        $this->assertSame(SlotState::Past, $this->stateAt($slots, 11), 'A slot already under way has started.');
        $this->assertSame(SlotState::Available, $this->stateAt($slots, 12));
    }

    public function test_a_slot_starting_exactly_at_this_moment_is_still_available(): void
    {
        // now() is 08:00 and the day opens at 08:00.
        $space = $this->openSpace();

        $slots = $this->availability->slotsForDay($space, $this->monday());

        $this->assertSame(SlotState::Available, $this->stateAt($slots, 8));
    }

    public function test_a_days_slots_cost_exactly_one_bookings_query(): void
    {
        // The whole point of passing a pre-fetched collection: a naive
        // implementation would query once per slot, 12 times for this day
        // and roughly 3000 times for a month.
        $space = $this->openSpace();
        $this->book($space, 10);

        $queries = 0;
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'from "bookings"')) {
                $queries++;
            }
        });

        $this->availability->slotsForDay($space, $this->monday());

        $this->assertSame(1, $queries);
    }

    public function test_a_month_reports_available_closed_and_full_days(): void
    {
        $space = $this->openSpace();
        BusinessHourException::factory()->create([
            'branch_id' => $space->building->branch_id,
            'date' => '2026-08-24',
            'is_closed' => true,
            'reason' => 'Public holiday',
        ]);

        $days = $this->availability->daysInRange(
            $space,
            $this->monday(),
            $this->monday()->copy()->addDays(7),
        );

        $byDate = [];
        /** @var DayAvailability $day */
        foreach ($days as $day) {
            $byDate[$day->date->format('Y-m-d')] = $day;
        }

        $this->assertSame(DayState::Available, $byDate['2026-08-17']->state);
        $this->assertSame(DayState::Closed, $byDate['2026-08-18']->state, 'A Tuesday with no schedule is closed.');
        $this->assertNull($byDate['2026-08-18']->closureReason, 'An unscheduled weekday has nothing to explain.');
        $this->assertSame(DayState::Closed, $byDate['2026-08-24']->state);
        $this->assertSame('Public holiday', $byDate['2026-08-24']->closureReason);
    }

    public function test_a_day_whose_every_slot_is_taken_is_full_rather_than_closed(): void
    {
        $space = $this->openSpace();

        // Fill the whole 08:00–20:00 day.
        $this->book($space, 8, 12 * 60);

        $days = $this->availability->daysInRange($space, $this->monday(), $this->monday());

        $this->assertSame(DayState::Full, $days[0]->state);
        $this->assertSame(0, $days[0]->availableSlotCount);
        $this->assertNotSame(0, $days[0]->totalSlotCount, 'A full day is open, so it still has slots.');
    }

    public function test_a_month_of_days_costs_a_bounded_number_of_queries(): void
    {
        $space = $this->openSpace();
        // Resolved outside the count: a fixed handful of settings reads per
        // request, not something that scales with the range.
        $rules = $this->availability->rulesFor($space);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->availability->daysInRange($space, $this->monday(), $this->monday()->copy()->addDays(30), $rules);

        // Business hours (1) + exceptions (1) + closure reasons (1) +
        // bookings (1), plus a little slack for relation loading — the
        // point is that it does not scale with the 31 days.
        $this->assertLessThanOrEqual(8, $queries, "A 31-day calendar issued {$queries} queries; it must not query per day.");
    }

    // ---------------------------------------------------------------
    // Peak concurrency, not a count of every booking touching the window
    // ---------------------------------------------------------------

    public function test_a_long_co_space_window_is_measured_by_its_busiest_moment_not_by_every_booking_it_touches(): void
    {
        $space = $this->openSpace([
            'space_type' => SpaceType::CoSpace,
            'capacity' => 2,
            'is_lockable' => false,
        ]);

        // One seat taken 09–10 and another 11–12: never two at once.
        $this->book($space, 9);
        $this->book($space, 11);

        $rules = $this->availability->rulesFor($space);
        $start = $this->monday()->copy()->setTime(9, 0)->utc();
        $end = $this->monday()->copy()->setTime(12, 0)->utc();
        $existing = $this->availability->blockingBookings($space, $start, $end, $rules);

        $this->assertFalse($this->availability->exceedsCeiling($existing, $start, $end, $rules));

        // A second seat taken 09–10 fills that hour.
        $this->book($space, 9);
        $existing = $this->availability->blockingBookings($space, $start, $end, $rules);

        $this->assertTrue($this->availability->exceedsCeiling($existing, $start, $end, $rules));
    }

    public function test_back_to_back_bookings_on_a_co_space_do_not_count_as_concurrent(): void
    {
        $space = $this->openSpace([
            'space_type' => SpaceType::CoSpace,
            'capacity' => 2,
            'is_lockable' => false,
        ]);

        // 08–09 ends exactly as 09–10 starts: one seat at any moment.
        $this->book($space, 8);
        $this->book($space, 9);

        $rules = $this->availability->rulesFor($space);
        $start = $this->monday()->copy()->setTime(8, 0)->utc();
        $end = $this->monday()->copy()->setTime(10, 0)->utc();
        $existing = $this->availability->blockingBookings($space, $start, $end, $rules);

        $this->assertFalse($this->availability->exceedsCeiling($existing, $start, $end, $rules));
    }

    // ---------------------------------------------------------------
    // A cell is only offered if a booking can actually start there
    // ---------------------------------------------------------------

    public function test_a_cell_too_close_to_the_next_booking_to_hold_the_minimum_duration_is_not_available(): void
    {
        // 30-minute cells, 60-minute minimum (the setting's default).
        $space = $this->openSpace(['slot_granularity_minutes' => 30]);
        $this->book($space, 11);

        $slots = $this->availability->slotsForDay($space, $this->monday());
        $at1030 = collect($slots)->first(fn (SlotAvailability $slot) => $slot->startAt->setTimezone('Asia/Damascus')->format('H:i') === '10:30');

        $this->assertSame(SlotState::Booked, $at1030->state, '10:30 cannot hold a 60-minute booking before 11:00.');
        $this->assertSame(SlotState::Available, $this->stateAt($slots, 10));
    }

    public function test_the_last_cell_of_the_day_is_outside_hours_when_the_minimum_duration_overruns_closing(): void
    {
        $space = $this->openSpace(['slot_granularity_minutes' => 30]);

        $slots = $this->availability->slotsForDay($space, $this->monday());
        $last = end($slots);

        $this->assertSame('19:30', $last->startAt->setTimezone('Asia/Damascus')->format('H:i'));
        $this->assertSame(SlotState::OutsideHours, $last->state);
    }

    // ---------------------------------------------------------------
    // Duration-aware slots
    // ---------------------------------------------------------------

    public function test_slots_for_a_requested_duration_span_the_whole_duration(): void
    {
        $space = $this->openSpace();
        $this->book($space, 10);

        $slots = $this->availability->slotsForDay($space, $this->monday(), durationMinutes: 120);

        $first = $slots[0];
        $this->assertSame('08:00', $first->startAt->setTimezone('Asia/Damascus')->format('H:i'));
        $this->assertSame('10:00', $first->endAt->setTimezone('Asia/Damascus')->format('H:i'));
        $this->assertSame(SlotState::Available, $first->state);

        // 09–11 runs into the 10:00 booking.
        $this->assertSame(SlotState::Booked, $this->stateAt($slots, 9));
        $this->assertSame(SlotState::Available, $this->stateAt($slots, 11));
    }

    public function test_a_start_that_cannot_fit_the_requested_duration_before_closing_is_not_listed(): void
    {
        $space = $this->openSpace();

        $slots = $this->availability->slotsForDay($space, $this->monday(), durationMinutes: 120);

        $this->assertSame('18:00', end($slots)->startAt->setTimezone('Asia/Damascus')->format('H:i'));
        $this->assertNull($this->stateAt($slots, 19));
    }

    public function test_a_day_with_hours_free_but_no_gap_long_enough_for_the_duration_is_full(): void
    {
        $space = $this->openSpace([], [['open' => '08:00', 'close' => '12:00']]);
        $this->book($space, 9);
        $this->book($space, 11);

        $day = $this->availability->daysInRange($space, $this->monday(), $this->monday(), durationMinutes: 120)[0];

        $this->assertSame(DayState::Full, $day->state);
        $this->assertSame(DayState::Available, $this->availability->daysInRange($space, $this->monday(), $this->monday())[0]->state);
    }

    // ---------------------------------------------------------------
    // Booking window and maximum duration
    // ---------------------------------------------------------------

    public function test_slots_inside_the_minimum_notice_are_too_soon(): void
    {
        app(SettingService::class)->set('booking.min_advance_minutes', 120, SettingValueType::Int);
        $space = $this->openSpace();

        $slots = $this->availability->slotsForDay($space, $this->monday());

        $this->assertSame(SlotState::TooSoon, $this->stateAt($slots, 9));
        $this->assertSame(SlotState::Available, $this->stateAt($slots, 10));
    }

    public function test_days_past_the_advance_window_are_out_of_range(): void
    {
        app(SettingService::class)->set('booking.max_advance_days', 7, SettingValueType::Int);
        $space = $this->openSpace();
        $nextMonday = $this->monday()->copy()->addWeek();
        $mondayAfter = $this->monday()->copy()->addWeeks(2);

        $this->assertSame(DayState::Available, $this->availability->daysInRange($space, $nextMonday, $nextMonday)[0]->state);
        $this->assertSame(DayState::OutOfRange, $this->availability->daysInRange($space, $mondayAfter, $mondayAfter)[0]->state);
    }

    public function test_a_duration_above_the_maximum_is_not_a_valid_duration(): void
    {
        app(SettingService::class)->set('booking.max_duration_minutes', 180, SettingValueType::Int);
        $rules = $this->availability->rulesFor($this->openSpace());
        $start = $this->monday()->copy()->setTime(8, 0);

        $this->assertTrue($this->availability->isShortEnough($start, $start->copy()->addMinutes(180), $rules));
        $this->assertFalse($this->availability->isShortEnough($start, $start->copy()->addMinutes(240), $rules));
        $this->assertFalse($this->availability->isValidDurationInMinutes(240, $rules));
    }

    public function test_no_maximum_duration_is_the_default(): void
    {
        $rules = $this->availability->rulesFor($this->openSpace());
        $start = $this->monday()->copy()->setTime(8, 0);

        $this->assertNull($rules->maxDurationMinutes);
        $this->assertTrue($this->availability->isShortEnough($start, $start->copy()->addMinutes(720), $rules));
    }
}
