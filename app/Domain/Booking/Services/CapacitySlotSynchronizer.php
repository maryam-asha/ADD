<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\SpaceCapacitySlot;
use App\Domain\Booking\ValueObjects\SpaceBookingRules;
use App\Domain\Foundation\Models\Space;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Keeps `space_capacity_slots` in step with `bookings`.
 *
 * Every mutation here is called from inside the caller's existing
 * `DB::transaction` and after its `Space::lockForUpdate()` — never on its
 * own — so the counter moves in the same atomic step as the booking that
 * caused it, and two concurrent bookings cannot both read the same count.
 *
 * This table is derived. `BookingCreationService` still decides
 * availability by counting `bookings` rows, so a drift here can make a
 * calendar cell stale but can never let a slot be oversold. `rebuildFor()`
 * and the `booking:reconcile-capacity-slots` command restore it from
 * `bookings` at any time — see the table's migration and
 * docs/decisions/booking-availability-and-calendar.md.
 */
class CapacitySlotSynchronizer
{
    public function __construct(private readonly AvailabilityService $availability) {}

    /**
     * Claim every slot the booking's window covers.
     */
    public function occupy(Booking $booking, Space $space, ?SpaceBookingRules $rules = null): void
    {
        $this->shift($booking, $space, 1, $rules);
    }

    /**
     * Release them again — cancellation, rejection, or a shortened window.
     */
    public function release(Booking $booking, Space $space, ?SpaceBookingRules $rules = null): void
    {
        $this->shift($booking, $space, -1, $rules);
    }

    /**
     * Move a booking's claim from an old window to a new one, which is what
     * an extension is. Releasing the whole old window and re-occupying the
     * new one keeps this correct whether the window grew, shrank or moved,
     * rather than trying to reason about the delta.
     */
    public function move(Booking $booking, Space $space, CarbonImmutable $previousStart, CarbonImmutable $previousEnd, ?SpaceBookingRules $rules = null): void
    {
        $rules ??= $this->availability->rulesFor($space);

        $this->shiftRange($space, $previousStart, $previousEnd, -1, $rules);
        $this->shiftRange($space, CarbonImmutable::instance($booking->start_at), CarbonImmutable::instance($booking->end_at), 1, $rules);
    }

    private function shift(Booking $booking, Space $space, int $delta, ?SpaceBookingRules $rules): void
    {
        $rules ??= $this->availability->rulesFor($space);

        $this->shiftRange(
            $space,
            CarbonImmutable::instance($booking->start_at),
            CarbonImmutable::instance($booking->end_at),
            $delta,
            $rules,
        );
    }

    /**
     * Walk the window on the granularity grid, adjusting one row per slot.
     *
     * A row is created on first claim and deleted once it falls back to
     * zero, so the table only ever holds slots that are actually booked.
     */
    private function shiftRange(
        Space $space,
        CarbonImmutable $start,
        CarbonImmutable $end,
        int $delta,
        SpaceBookingRules $rules,
    ): void {
        foreach ($this->slotBoundaries($start, $end, $rules) as [$slotStart, $slotEnd]) {
            $slot = SpaceCapacitySlot::query()->firstOrNew(
                ['space_id' => $space->id, 'slot_start' => $slotStart],
                ['slot_end' => $slotEnd, 'booked_count' => 0, 'capacity_ceiling' => $rules->concurrencyCeiling],
            );

            $slot->booked_count = max(0, $slot->booked_count + $delta);

            if ($slot->booked_count === 0) {
                if ($slot->exists) {
                    $slot->delete();
                }

                continue;
            }

            $slot->slot_end = $slotEnd;
            $slot->capacity_ceiling = $rules->concurrencyCeiling;
            $slot->save();
        }
    }

    /**
     * The slot grid a window covers, aligned to the granularity boundary
     * below the window's start so two bookings of the same slot always
     * address the identical row.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function slotBoundaries(CarbonImmutable $start, CarbonImmutable $end, SpaceBookingRules $rules): array
    {
        $granularity = max(1, $rules->granularityMinutes);

        $local = $start->setTimezone($rules->timezone);
        $minutesSinceMidnight = $local->hour * 60 + $local->minute;
        $overshoot = $minutesSinceMidnight % $granularity;

        $cursor = $local->subMinutes($overshoot)->startOfMinute();
        $boundaries = [];

        while ($cursor->lessThan($end->setTimezone($rules->timezone))) {
            $next = $cursor->addMinutes($granularity);
            $boundaries[] = [$cursor->utc(), $next->utc()];
            $cursor = $next;
        }

        return $boundaries;
    }

    /**
     * Rebuild one space's rows from `bookings` — the reconciliation path,
     * and the definition of what this table is supposed to contain.
     *
     * @return array{rebuilt: int, removed: int}
     */
    public function rebuildFor(Space $space, ?SpaceBookingRules $rules = null): array
    {
        $rules ??= $this->availability->rulesFor($space);

        $removed = SpaceCapacitySlot::query()->where('space_id', $space->id)->delete();

        $counts = [];

        Booking::query()
            ->forSpace($space->id)
            ->blocking()
            ->orderBy('start_at')
            ->each(function (Booking $booking) use (&$counts, $rules) {
                foreach ($this->slotBoundaries(
                    CarbonImmutable::instance($booking->start_at),
                    CarbonImmutable::instance($booking->end_at),
                    $rules,
                ) as [$slotStart, $slotEnd]) {
                    $key = $slotStart->toDateTimeString();
                    $counts[$key] ??= ['start' => $slotStart, 'end' => $slotEnd, 'count' => 0];
                    $counts[$key]['count']++;
                }
            });

        foreach ($counts as $row) {
            SpaceCapacitySlot::query()->create([
                'space_id' => $space->id,
                'slot_start' => $row['start'],
                'slot_end' => $row['end'],
                'booked_count' => $row['count'],
                'capacity_ceiling' => $rules->concurrencyCeiling,
            ]);
        }

        return ['rebuilt' => count($counts), 'removed' => $removed];
    }

    /**
     * What the table currently says, versus what `bookings` implies — the
     * drift check the reconcile command reports.
     *
     * @return Collection<int, array{slot_start: string, stored: int, expected: int}>
     */
    public function driftFor(Space $space, ?SpaceBookingRules $rules = null): Collection
    {
        $rules ??= $this->availability->rulesFor($space);

        $expected = [];

        Booking::query()
            ->forSpace($space->id)
            ->blocking()
            ->get()
            ->each(function (Booking $booking) use (&$expected, $rules) {
                foreach ($this->slotBoundaries(
                    CarbonImmutable::instance($booking->start_at),
                    CarbonImmutable::instance($booking->end_at),
                    $rules,
                ) as [$slotStart]) {
                    $key = $slotStart->toDateTimeString();
                    $expected[$key] = ($expected[$key] ?? 0) + 1;
                }
            });

        $stored = SpaceCapacitySlot::query()
            ->where('space_id', $space->id)
            ->get()
            ->mapWithKeys(fn (SpaceCapacitySlot $slot) => [
                CarbonImmutable::instance($slot->slot_start)->toDateTimeString() => $slot->booked_count,
            ])
            ->all();

        return collect(array_unique([...array_keys($expected), ...array_keys($stored)]))
            ->map(fn (string $key) => [
                'slot_start' => $key,
                'stored' => $stored[$key] ?? 0,
                'expected' => $expected[$key] ?? 0,
            ])
            ->filter(fn (array $row) => $row['stored'] !== $row['expected'])
            ->values();
    }
}
