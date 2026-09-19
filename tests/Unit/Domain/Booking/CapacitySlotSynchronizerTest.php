<?php

namespace Tests\Unit\Domain\Booking;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\SpaceCapacitySlot;
use App\Domain\Booking\Services\BookingApprovalService;
use App\Domain\Booking\Services\BookingCancellationService;
use App\Domain\Booking\Services\BookingCreationService;
use App\Domain\Booking\Services\CapacitySlotSynchronizer;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `space_capacity_slots` is derived from `bookings`. These tests pin the
 * one property that makes it safe to keep: whatever path moved a booking,
 * the table afterwards equals a rebuild from `bookings`.
 *
 * Drift here can stale a calendar cell but can never oversell a slot —
 * BookingCreationService counts `bookings` under a row lock, not this
 * table. See docs/decisions/booking-availability-and-calendar.md.
 */
class CapacitySlotSynchronizerTest extends TestCase
{
    use RefreshDatabase;

    private CapacitySlotSynchronizer $synchronizer;

    private BookingCreationService $creations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->synchronizer = app(CapacitySlotSynchronizer::class);
        $this->creations = app(BookingCreationService::class);
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
     */
    private function openSpace(array $attributes = []): Space
    {
        $space = Space::factory()->room()->create(array_merge([
            'hourly_rate_points' => 100,
            'slot_granularity_minutes' => 60,
        ], $attributes));

        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function slot(int $hour, int $durationMinutes = 60): array
    {
        $start = Carbon::parse('2026-08-17', 'Asia/Damascus')->setTime($hour, 0);

        return [$start, $start->copy()->addMinutes($durationMinutes)];
    }

    /**
     * The invariant this whole table rests on.
     */
    private function assertMatchesRebuild(Space $space): void
    {
        $this->assertSame(
            [],
            $this->synchronizer->driftFor($space)->all(),
            'space_capacity_slots disagrees with a rebuild from bookings.'
        );
    }

    public function test_creating_a_booking_claims_one_row_per_covered_slot(): void
    {
        $space = $this->openSpace();
        [$start, $end] = $this->slot(10, 120);

        $this->creations->create($space, User::factory()->create(), $start, $end);

        $slots = SpaceCapacitySlot::where('space_id', $space->id)->get();

        $this->assertCount(2, $slots, 'A two-hour booking on an hourly grid covers two slots.');
        $this->assertSame([1, 1], $slots->pluck('booked_count')->all());
        $this->assertMatchesRebuild($space);
    }

    public function test_a_second_co_space_booking_increments_the_same_row_rather_than_adding_one(): void
    {
        $space = $this->openSpace([
            'space_type' => SpaceType::CoSpace,
            'is_lockable' => false,
            'capacity' => 3,
        ]);
        [$start, $end] = $this->slot(10);

        $this->creations->create($space, User::factory()->create(), $start, $end);
        $this->creations->create($space, User::factory()->create(), $start, $end);

        $slots = SpaceCapacitySlot::where('space_id', $space->id)->get();

        $this->assertCount(1, $slots);
        $this->assertSame(2, $slots->first()->booked_count);
        $this->assertSame(3, $slots->first()->capacity_ceiling);
        $this->assertMatchesRebuild($space);
    }

    public function test_cancelling_a_booking_releases_its_rows_entirely(): void
    {
        $space = $this->openSpace();
        [$start, $end] = $this->slot(14);
        $booking = $this->creations->create($space, User::factory()->create(), $start, $end);

        $this->assertSame(1, SpaceCapacitySlot::where('space_id', $space->id)->count());

        app(BookingCancellationService::class)->cancel($booking);

        $this->assertSame(
            0,
            SpaceCapacitySlot::where('space_id', $space->id)->count(),
            'A row that falls back to zero is deleted, so the table only holds slots that are actually booked.'
        );
        $this->assertMatchesRebuild($space);
    }

    public function test_rejecting_a_pending_booking_releases_its_rows(): void
    {
        $space = $this->openSpace(['requires_approval' => true]);
        [$start, $end] = $this->slot(14);
        $booking = $this->creations->create($space, User::factory()->create(), $start, $end);

        $this->assertSame(1, SpaceCapacitySlot::where('space_id', $space->id)->count());

        $operator = User::factory()->create();
        $operator->assignRole('operations');
        app(BookingApprovalService::class)->reject($booking, $operator, 'Room needed for maintenance');

        $this->assertSame(0, SpaceCapacitySlot::where('space_id', $space->id)->count());
        $this->assertMatchesRebuild($space);
    }

    public function test_approving_a_pending_booking_leaves_its_claim_untouched(): void
    {
        // A pending booking already held the slot, so confirming it must
        // not double-count.
        $space = $this->openSpace(['requires_approval' => true]);
        [$start, $end] = $this->slot(14);
        $booking = $this->creations->create($space, User::factory()->create(), $start, $end);

        $operator = User::factory()->create();
        $operator->assignRole('operations');
        app(BookingApprovalService::class)->approve($booking, $operator);

        $this->assertSame(1, SpaceCapacitySlot::where('space_id', $space->id)->sum('booked_count'));
        $this->assertMatchesRebuild($space);
    }

    public function test_rebuilding_restores_the_table_after_it_is_tampered_with(): void
    {
        // Stand in for a restored backup or a manual SQL fix — the cases a
        // transaction cannot protect against, and the reason the rebuild
        // command exists.
        $space = $this->openSpace();
        [$start, $end] = $this->slot(10, 120);
        $this->creations->create($space, User::factory()->create(), $start, $end);

        SpaceCapacitySlot::where('space_id', $space->id)->delete();
        $this->assertNotSame([], $this->synchronizer->driftFor($space)->all(), 'Drift must be detectable.');

        $this->synchronizer->rebuildFor($space);

        $this->assertSame(2, SpaceCapacitySlot::where('space_id', $space->id)->count());
        $this->assertMatchesRebuild($space);
    }

    public function test_drift_reports_the_slot_and_both_counts(): void
    {
        $space = $this->openSpace();
        [$start, $end] = $this->slot(10);
        $this->creations->create($space, User::factory()->create(), $start, $end);

        SpaceCapacitySlot::where('space_id', $space->id)->update(['booked_count' => 7]);

        $drift = $this->synchronizer->driftFor($space);

        $this->assertCount(1, $drift);
        $this->assertSame(7, $drift->first()['stored']);
        $this->assertSame(1, $drift->first()['expected']);
    }

    public function test_the_reconcile_command_reports_drift_and_repairs_it(): void
    {
        $space = $this->openSpace();
        [$start, $end] = $this->slot(10);
        $this->creations->create($space, User::factory()->create(), $start, $end);

        SpaceCapacitySlot::where('space_id', $space->id)->update(['booked_count' => 9]);

        $this->artisan('booking:reconcile-capacity-slots')
            ->expectsOutputToContain('out of step with bookings')
            ->assertSuccessful();

        $this->assertMatchesRebuild($space);
    }

    public function test_a_dry_run_reports_drift_without_repairing_it(): void
    {
        $space = $this->openSpace();
        [$start, $end] = $this->slot(10);
        $this->creations->create($space, User::factory()->create(), $start, $end);

        SpaceCapacitySlot::where('space_id', $space->id)->update(['booked_count' => 9]);

        $this->artisan('booking:reconcile-capacity-slots', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(
            9,
            (int) SpaceCapacitySlot::where('space_id', $space->id)->value('booked_count'),
            'A dry run must not rewrite anything.'
        );
    }

    public function test_two_bookings_in_different_hours_claim_different_rows(): void
    {
        $space = $this->openSpace();

        [$startA, $endA] = $this->slot(10);
        [$startB, $endB] = $this->slot(12);

        $this->creations->create($space, User::factory()->create(), $startA, $endA);
        $this->creations->create($space, User::factory()->create(), $startB, $endB);

        $this->assertSame(2, SpaceCapacitySlot::where('space_id', $space->id)->count());
        $this->assertSame(2, (int) SpaceCapacitySlot::where('space_id', $space->id)->sum('booked_count'));
        $this->assertMatchesRebuild($space);
    }

    public function test_the_counter_never_makes_a_slot_oversellable(): void
    {
        // The safety property: even with the table wrong in the permissive
        // direction, creation still refuses the overlap, because it counts
        // bookings under a lock rather than reading this table.
        $space = $this->openSpace();
        [$start, $end] = $this->slot(14);
        $this->creations->create($space, User::factory()->create(), $start, $end);

        SpaceCapacitySlot::where('space_id', $space->id)->update(['booked_count' => 0]);

        $this->expectException(ReceptionActionException::class);

        $this->creations->create($space, User::factory()->create(), $start, $end);
    }

    public function test_a_booking_is_still_refused_when_the_counter_is_missing_entirely(): void
    {
        $space = $this->openSpace();
        [$start, $end] = $this->slot(14);
        $this->creations->create($space, User::factory()->create(), $start, $end);

        SpaceCapacitySlot::where('space_id', $space->id)->delete();

        $this->expectException(ReceptionActionException::class);

        $this->creations->create($space, User::factory()->create(), $start, $end);
    }

    public function test_booking_rows_remain_the_authority_after_a_rebuild(): void
    {
        $space = $this->openSpace();
        [$start, $end] = $this->slot(14);
        $this->creations->create($space, User::factory()->create(), $start, $end);

        $this->synchronizer->rebuildFor($space);

        $this->assertSame(
            1,
            Booking::query()->forSpace($space->id)->blocking()->count(),
            'Rebuilding the derived table must never change the bookings it is derived from.'
        );
        $this->assertMatchesRebuild($space);
    }
}
