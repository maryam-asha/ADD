<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\DayState;
use App\Domain\Booking\Enums\SlotState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\ValueObjects\DayAvailability;
use App\Domain\Booking\ValueObjects\SlotAvailability;
use App\Domain\Booking\ValueObjects\SpaceBookingRules;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\BusinessHourException;
use App\Domain\Foundation\Models\Space;
use App\Domain\Foundation\Services\BusinessHoursService;
use App\Domain\Settings\Services\SettingService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The one place that knows whether a booking window is legal.
 *
 * Before this existed, every rule lived as a private `assert*` on
 * BookingCreationService, which could only answer "is THIS window legal?"
 * by throwing. The calendar needs "WHICH windows are legal?" across a
 * range — and answering that by restating the rules in a second service
 * would guarantee the grid and the POST disagree forever, silently, in
 * whichever direction the two copies drifted.
 *
 * So the rules live here exactly once, as predicates that return bool.
 * BookingCreationService keeps its `assert*` methods but each is now a
 * one-line delegation that turns `false` into the same
 * ReceptionActionException, with the same message key, in the same order
 * as before. tests/Guards/AvailabilityRulesAreCentralizedTest keeps it
 * that way.
 *
 * ## The read path is advisory, never a reservation
 *
 * The contended predicates (overlap, buffer) take an already-fetched
 * Collection of bookings, so projecting a month costs one query rather
 * than 3000. The write path calls those same predicates, but passes a
 * collection it re-read INSIDE its own `DB::transaction` after
 * `Space::lockForUpdate()`. Nothing the calendar computed is ever trusted
 * to create a booking — the lock and the re-check are what make the
 * ceiling strict, and they have not moved.
 */
class AvailabilityService
{
    public function __construct(
        private readonly BusinessHoursService $businessHours,
        private readonly SettingService $settings,
    ) {}

    /**
     * Resolve every space-vs-setting fallback once per request.
     */
    public function rulesFor(Space $space): SpaceBookingRules
    {
        return new SpaceBookingRules(
            granularityMinutes: (int) ($space->slot_granularity_minutes
                ?? $this->settings->get('booking.slot_granularity_minutes', 30)),
            minDurationMinutes: (int) $this->settings->get('booking.min_duration_minutes', 60),
            bufferMinutes: (int) ($space->buffer_minutes
                ?? $this->settings->get('booking.buffer_minutes', 0)),
            cancellationWindowMinutes: (int) ($space->cancellation_window_minutes
                ?? $this->settings->get('booking.cancellation_window_minutes', 60)),
            requiresApproval: (bool) $space->requires_approval,
            capacity: $space->capacity,
            concurrencyCeiling: $this->concurrencyCeilingFor($space),
            timezone: (string) $this->settings->get('app.timezone', 'Asia/Damascus'),
            minNoticeMinutes: max(0, (int) $this->settings->get('booking.min_notice_minutes', 0)),
            maxDaysAhead: $this->positiveOrNull($this->settings->get('booking.max_days_ahead')),
        );
    }

    private function positiveOrNull(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * How many bookings may hold the same window at once.
     *
     * A co_space sells seats, not the room: with capacity 20, the second
     * member to book 10:00 was previously refused while 19 desks sat empty,
     * which contradicts the PRD's "no overbooking in Co-Space — the
     * capacity ceiling is strict" (the ceiling is N, not 1). Every other
     * type is exclusive, and `>= 1` reproduces the old `exists()` check
     * exactly, so their behaviour is unchanged.
     *
     * A co_space with no capacity set keeps a ceiling of 1 — the safe
     * default, since a null capacity is an unconfigured space rather than
     * an unlimited one.
     */
    private function concurrencyCeilingFor(Space $space): int
    {
        return match ($space->space_type) {
            SpaceType::CoSpace => max(1, $space->capacity ?? 1),
            default => 1,
        };
    }

    // ---------------------------------------------------------------
    // Stateless predicates — no DB, safe to call per slot
    // ---------------------------------------------------------------

    /**
     * Whether the space takes bookings at all. Only an active space does: one
     * under maintenance or retired disappears from every booking surface
     * (PRD §5.6).
     *
     * One definition for both sides: the read path 404s on it (the member
     * list, details and availability never show such a space) and the write
     * path refuses on it — without the second, a screen opened just before a
     * space went into maintenance, or a direct call, could still book it.
     */
    public function acceptsBookings(Space $space): bool
    {
        return $space->status === OperationalStatus::Active;
    }

    /**
     * A window must fall entirely inside ONE open period of the branch's
     * day. Spanning midnight or straddling a lunch closure both fail.
     */
    public function isWithinBusinessHours(
        Space $space,
        CarbonInterface $start,
        CarbonInterface $end,
        SpaceBookingRules $rules,
    ): bool {
        $branch = $space->building->branch;

        $localStart = $start->copy()->setTimezone($rules->timezone);
        $localEnd = $end->copy()->setTimezone($rules->timezone);

        if (! $localStart->isSameDay($localEnd)) {
            return false;
        }

        $startTime = $localStart->format('H:i');
        $endTime = $localEnd->format('H:i');

        foreach ($this->businessHours->periodsFor($start, $branch) as $period) {
            if ($startTime >= $period['open_time'] && $endTime <= $period['close_time']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Starts land on the granularity grid measured from local midnight.
     */
    public function isValidGranularity(CarbonInterface $start, SpaceBookingRules $rules): bool
    {
        $local = $start->copy()->setTimezone($rules->timezone);
        $minutesSinceMidnight = $local->hour * 60 + $local->minute;

        return $local->second === 0
            && $minutesSinceMidnight % $rules->granularityMinutes === 0;
    }

    /**
     * Split from matchesDurationGranularity() so the write path can keep
     * its two distinct error keys (`duration_too_short` vs
     * `duration_invalid_granularity`) rather than collapsing them.
     */
    public function isLongEnough(CarbonInterface $start, CarbonInterface $end, SpaceBookingRules $rules): bool
    {
        return (int) $start->diffInMinutes($end) >= $rules->minDurationMinutes;
    }

    public function matchesDurationGranularity(CarbonInterface $start, CarbonInterface $end, SpaceBookingRules $rules): bool
    {
        $duration = (int) $start->diffInMinutes($end);

        return ($duration - $rules->minDurationMinutes) % $rules->granularityMinutes === 0;
    }

    public function isValidDuration(CarbonInterface $start, CarbonInterface $end, SpaceBookingRules $rules): bool
    {
        return $this->isLongEnough($start, $end, $rules)
            && $this->matchesDurationGranularity($start, $end, $rules);
    }

    /**
     * Whether a bare number of minutes is a legal booking length.
     *
     * An extension adds an interval rather than moving a start, so it has
     * minutes but no window to measure — same rule, different input.
     */
    public function isValidDurationInMinutes(int $minutes, SpaceBookingRules $rules): bool
    {
        return $minutes >= $rules->minDurationMinutes
            && ($minutes - $rules->minDurationMinutes) % $rules->granularityMinutes === 0;
    }

    /**
     * The minimum notice: a start closer to now than `min_notice_minutes`
     * cannot be booked.
     *
     * Zero (the default) means there is no notice rule at all — not "must
     * start after now". Whether a past start is allowed is the separate
     * `booking.allow_past_bookings` decision, and answering it here too
     * would silently override that setting's back-dating switch.
     */
    public function isFarEnoughAhead(CarbonInterface $start, SpaceBookingRules $rules, ?CarbonInterface $now = null): bool
    {
        if ($rules->minNoticeMinutes === 0) {
            return true;
        }

        $now ??= CarbonImmutable::now('UTC');

        return $start->greaterThanOrEqualTo($now->copy()->addMinutes($rules->minNoticeMinutes));
    }

    /**
     * The booking horizon, counted in local calendar days: with 7, a member
     * can book any time up to and including the same weekday next week.
     * Days rather than a rolling 168 hours, so a whole calendar day is
     * either inside the horizon or outside it — never half of one.
     */
    public function isWithinAdvanceHorizon(CarbonInterface $start, SpaceBookingRules $rules, ?CarbonInterface $now = null): bool
    {
        if ($rules->maxDaysAhead === null) {
            return true;
        }

        return ! $this->isDateBeyondHorizon($start->copy()->setTimezone($rules->timezone), $rules, $now);
    }

    private function isDateBeyondHorizon(CarbonInterface $localDate, SpaceBookingRules $rules, ?CarbonInterface $now = null): bool
    {
        if ($rules->maxDaysAhead === null) {
            return false;
        }

        $lastDate = ($now ?? CarbonImmutable::now('UTC'))
            ->copy()
            ->setTimezone($rules->timezone)
            ->addDays($rules->maxDaysAhead)
            ->format('Y-m-d');

        return $localDate->format('Y-m-d') > $lastDate;
    }

    // ---------------------------------------------------------------
    // Contended predicates — operate on a pre-fetched collection, zero queries
    // ---------------------------------------------------------------

    /**
     * How many already-blocking bookings intersect this window.
     *
     * Compared against `$rules->concurrencyCeiling` by both callers, so a
     * room refuses at 1 and a co_space refuses at its capacity.
     *
     * @param  Collection<int, Booking>  $existing
     */
    public function overlapCount(
        Collection $existing,
        CarbonInterface $start,
        CarbonInterface $end,
        ?int $ignoreBookingId = null,
    ): int {
        return $existing
            ->when($ignoreBookingId !== null, fn (Collection $rows) => $rows->reject(
                fn (Booking $booking) => $booking->getKey() === $ignoreBookingId
            ))
            ->filter(fn (Booking $booking) => $booking->start_at < $end && $booking->end_at > $start)
            ->count();
    }

    /**
     * Whether the window would push the space past its concurrency ceiling
     * at any single moment.
     *
     * Measured at the busiest instant inside the window, not by counting
     * every booking that touches it: on a co_space of capacity 2, seats
     * taken 09–10 and 11–12 never coexist, so 09–12 still has a free seat
     * throughout — overlapCount() would report 2 and refuse it. Concurrency
     * can only rise when a booking starts, so the busiest instant is always
     * the window's own start or some booking's start inside it; checking
     * those points is exhaustive. For a ceiling of 1 this is exactly
     * "any overlap", so exclusive spaces are unchanged.
     *
     * @param  Collection<int, Booking>  $existing
     */
    public function exceedsCeiling(
        Collection $existing,
        CarbonInterface $start,
        CarbonInterface $end,
        SpaceBookingRules $rules,
        ?int $ignoreBookingId = null,
    ): bool {
        $overlapping = $existing
            ->when($ignoreBookingId !== null, fn (Collection $rows) => $rows->reject(
                fn (Booking $booking) => $booking->getKey() === $ignoreBookingId
            ))
            ->filter(fn (Booking $booking) => $booking->start_at < $end && $booking->end_at > $start)
            ->values();

        if ($overlapping->count() < $rules->concurrencyCeiling) {
            return false;
        }

        $instants = $overlapping
            ->map(fn (Booking $booking) => $booking->start_at)
            ->filter(fn (CarbonInterface $instant) => $instant > $start)
            ->push($start);

        foreach ($instants as $instant) {
            $concurrent = $overlapping
                ->filter(fn (Booking $booking) => $booking->start_at <= $instant && $booking->end_at > $instant)
                ->count();

            if ($concurrent >= $rules->concurrencyCeiling) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether another booking sits closer than the space's buffer allows.
     *
     * A gap exactly equal to buffer_minutes passes — the inclusive
     * boundary this domain already uses for business hours
     * (docs/decisions/booking-creation-approval-extension.md).
     *
     * @param  Collection<int, Booking>  $existing
     */
    public function violatesBuffer(
        Collection $existing,
        CarbonInterface $start,
        CarbonInterface $end,
        SpaceBookingRules $rules,
        ?int $ignoreBookingId = null,
    ): bool {
        if (! $rules->bufferApplies()) {
            return false;
        }

        $buffer = $rules->bufferMinutes;
        $earliest = $start->copy()->subMinutes($buffer);
        $latest = $end->copy()->addMinutes($buffer);

        return $existing
            ->when($ignoreBookingId !== null, fn (Collection $rows) => $rows->reject(
                fn (Booking $booking) => $booking->getKey() === $ignoreBookingId
            ))
            ->contains(function (Booking $booking) use ($start, $end, $earliest, $latest) {
                $endsTooClose = $booking->end_at <= $start && $booking->end_at > $earliest;
                $startsTooClose = $booking->start_at >= $end && $booking->start_at < $latest;

                return $endsTooClose || $startsTooClose;
            });
    }

    // ---------------------------------------------------------------
    // The one place blocking bookings are read from the database
    // ---------------------------------------------------------------

    /**
     * Every booking that could block something in this range, fetched once.
     *
     * The range is padded by the buffer on both ends: a booking that ends
     * just before `rangeStart` still forbids a slot at `rangeStart`, and
     * would be invisible to an unpadded query.
     *
     * @return Collection<int, Booking>
     */
    public function blockingBookings(
        Space $space,
        CarbonInterface $rangeStart,
        CarbonInterface $rangeEnd,
        SpaceBookingRules $rules,
    ): Collection {
        $padding = max($rules->bufferMinutes, 0);

        return Booking::query()
            ->forSpace($space->id)
            ->blocking()
            ->overlapping(
                $rangeStart->copy()->subMinutes($padding),
                $rangeEnd->copy()->addMinutes($padding),
            )
            ->orderBy('start_at')
            ->get();
    }

    // ---------------------------------------------------------------
    // Projections — what the read path calls
    // ---------------------------------------------------------------

    /**
     * Every slot of one local day, each tagged with why it is or is not
     * bookable.
     *
     * Slots are generated across the day's open periods only; the grid the
     * app draws is the union of those, so a closed day yields none.
     *
     * Each slot is a granularity-sized cell of the grid. Two questions, one
     * set of predicates:
     *
     * - By default a cell's state answers "can a booking START here": it is
     *   judged against the shortest legal booking from that start. That is
     *   what a day's availability counts — a day of scattered half-hours
     *   with a 60-minute minimum is full.
     * - `eachCellAlone` judges each cell on itself: "is this stretch free".
     *   That is what a picker of runs needs — the 10:30 cell before an 11:00
     *   booking cannot start a 60-minute booking, yet it is a valid last
     *   cell of 10:00–11:00. The minimum then applies to the run.
     *
     * @param  Collection<int, Booking>|null  $prefetched
     * @return list<SlotAvailability>
     */
    public function slotsForDay(
        Space $space,
        CarbonInterface $localDate,
        ?SpaceBookingRules $rules = null,
        ?Collection $prefetched = null,
        ?array $periods = null,
        bool $eachCellAlone = false,
    ): array {
        $rules ??= $this->rulesFor($space);
        $branch = $space->building->branch;

        $dayStart = CarbonImmutable::parse($localDate->format('Y-m-d').' 00:00:00', $rules->timezone);
        $dayEnd = $dayStart->addDay();

        $periods ??= $this->businessHours->periodsFor($dayStart, $branch);

        if ($periods === []) {
            return [];
        }

        $existing = $prefetched ?? $this->blockingBookings($space, $dayStart->utc(), $dayEnd->utc(), $rules);

        $now = CarbonImmutable::now('UTC');
        $slots = [];

        // The length each cell is judged by, and the length it spans.
        $spanMinutes = $rules->granularityMinutes;
        $judgedMinutes = $eachCellAlone ? $spanMinutes : max($spanMinutes, $rules->minDurationMinutes);

        foreach ($periods as $period) {
            $cursor = CarbonImmutable::parse(
                $dayStart->format('Y-m-d').' '.$period['open_time'],
                $rules->timezone
            );
            $close = CarbonImmutable::parse(
                $dayStart->format('Y-m-d').' '.$period['close_time'],
                $rules->timezone
            );

            while ($cursor->addMinutes($spanMinutes)->lessThanOrEqualTo($close)) {
                $slotStart = $cursor->utc();

                $slots[] = new SlotAvailability(
                    startAt: $slotStart,
                    endAt: $cursor->addMinutes($spanMinutes)->utc(),
                    state: $this->stateFor(
                        $slotStart,
                        $cursor->addMinutes($judgedMinutes)->utc(),
                        $close->utc(),
                        $existing,
                        $rules,
                        $now,
                    ),
                );

                $cursor = $cursor->addMinutes($rules->granularityMinutes);
            }
        }

        return $slots;
    }

    /**
     * The state of one candidate window, in the order the write path
     * checks: a start already gone is past, then the booking window
     * (notice, horizon), then hours, then taken, then blocked by a
     * neighbour's buffer.
     *
     * @param  Collection<int, Booking>  $existing
     */
    private function stateFor(
        CarbonInterface $start,
        CarbonInterface $end,
        CarbonInterface $periodClose,
        Collection $existing,
        SpaceBookingRules $rules,
        CarbonInterface $now,
    ): SlotState {
        if ($start->lessThan($now)) {
            return SlotState::Past;
        }

        if (! $this->isFarEnoughAhead($start, $rules, $now)) {
            return SlotState::TooSoon;
        }

        if (! $this->isWithinAdvanceHorizon($start, $rules, $now)) {
            return SlotState::TooFarAhead;
        }

        if ($end->greaterThan($periodClose)) {
            return SlotState::OutsideHours;
        }

        if ($this->exceedsCeiling($existing, $start, $end, $rules)) {
            return $rules->allowsConcurrentBookings()
                ? SlotState::AtCapacity
                : SlotState::Booked;
        }

        if ($this->violatesBuffer($existing, $start, $end, $rules)) {
            return SlotState::Buffer;
        }

        return SlotState::Available;
    }

    /**
     * A month of day states, in a bounded number of queries.
     *
     * Business hours for the whole range are resolved in two queries by
     * BusinessHoursService::periodsForRange(), and every space's bookings
     * in one — so a 31-day calendar costs three queries, not 93.
     *
     * "Available" means at least one start can hold a booking of the
     * minimum length — a day whose free gaps are all shorter reads as full,
     * which is what the member will find on it.
     *
     * @return list<DayAvailability>
     */
    public function daysInRange(
        Space $space,
        CarbonInterface $fromLocalDate,
        CarbonInterface $toLocalDate,
        ?SpaceBookingRules $rules = null,
    ): array {
        $rules ??= $this->rulesFor($space);
        $branch = $space->building->branch;

        $from = CarbonImmutable::parse($fromLocalDate->format('Y-m-d').' 00:00:00', $rules->timezone);
        $to = CarbonImmutable::parse($toLocalDate->format('Y-m-d').' 00:00:00', $rules->timezone);

        $periodsByDate = $this->businessHours->periodsForRange($from, $to, $branch);
        $reasonsByDate = $this->closureReasonsForRange($from, $to, $branch);

        $existing = $this->blockingBookings($space, $from->utc(), $to->addDay()->utc(), $rules);

        $today = CarbonImmutable::now($rules->timezone)->startOfDay();
        $days = [];

        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            $key = $date->format('Y-m-d');
            $periods = $periodsByDate[$key] ?? [];

            if ($date->lessThan($today)) {
                $days[] = new DayAvailability(
                    date: $date,
                    state: DayState::Past,
                    periods: $periods,
                    closureReason: null,
                    availableSlotCount: 0,
                    totalSlotCount: 0,
                );

                continue;
            }

            if ($this->isDateBeyondHorizon($date, $rules)) {
                $days[] = new DayAvailability(
                    date: $date,
                    state: DayState::OutOfRange,
                    periods: $periods,
                    closureReason: null,
                    availableSlotCount: 0,
                    totalSlotCount: 0,
                );

                continue;
            }

            if ($periods === []) {
                $days[] = new DayAvailability(
                    date: $date,
                    state: DayState::Closed,
                    periods: [],
                    closureReason: $reasonsByDate[$key] ?? null,
                    availableSlotCount: 0,
                    totalSlotCount: 0,
                );

                continue;
            }

            $slots = $this->slotsForDay($space, $date, $rules, $existing, $periods);
            $available = count(array_filter($slots, fn (SlotAvailability $slot) => $slot->isAvailable()));

            $days[] = new DayAvailability(
                date: $date,
                state: $available > 0 ? DayState::Available : DayState::Full,
                periods: $periods,
                closureReason: null,
                availableSlotCount: $available,
                totalSlotCount: count($slots),
            );
        }

        return $days;
    }

    /**
     * Closure reasons for the range, keyed by date, in one query.
     *
     * Only a BusinessHourException can explain a closure; a weekday with no
     * schedule is closed with nothing to say about it, which is exactly the
     * distinction BusinessHoursService::periodsFor()'s docblock says a
     * caller must come here for.
     *
     * @return array<string, string|null>
     */
    private function closureReasonsForRange(CarbonInterface $from, CarbonInterface $to, Branch $branch): array
    {
        // whereDate on both bounds, not whereBetween: `date` is a DATE
        // column cast to Carbon, and a plain string range silently drops
        // the boundary days. Same correction as BusinessHoursService.
        return BusinessHourException::query()
            ->where('branch_id', $branch->id)
            ->where('is_closed', true)
            ->whereDate('date', '>=', $from->format('Y-m-d'))
            ->whereDate('date', '<=', $to->format('Y-m-d'))
            ->get(['date', 'reason'])
            ->mapWithKeys(fn (BusinessHourException $exception) => [
                CarbonImmutable::parse($exception->date)->format('Y-m-d') => $exception->reason,
            ])
            ->all();
    }
}
