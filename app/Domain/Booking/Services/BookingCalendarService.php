<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\ValueObjects\PoolTimeline;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Space;
use App\Domain\Foundation\Services\BusinessHoursService;
use App\Domain\Leasing\Models\OfficeLease;
use App\Support\Presenters\AvailabilityPresenter;
use App\Support\Time\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The operations calendar: every space as a row, every day in the view as
 * a cell, with what is booked and how full it is.
 *
 * Occupancy is booked minutes over open minutes (OccupancyMeter), where
 * "open" comes from the same BusinessHoursService periods the member
 * calendar uses. That
 * matters: if this computed its own notion of open hours, a room could
 * read 60% full here and be offered as free there.
 *
 * Everything is loaded in a bounded number of queries — business hours for
 * the range, then one bookings fetch across every space — so adding spaces
 * or days does not add queries.
 */
class BookingCalendarService
{
    /**
     * A month view for a whole branch is already a lot of cells; beyond
     * this the response stops being a calendar and starts being a report.
     */
    public const MAX_DAYS = 31;

    public function __construct(
        private readonly BusinessHoursService $businessHours,
        private readonly AvailabilityService $availability,
        private readonly AvailabilityPresenter $presenter,
        private readonly LocalTime $time,
        private readonly OccupancyMeter $meter,
    ) {}

    /**
     * `member_id` narrows the bookings listed in each cell, and the totals'
     * count of them, to that member's. A cell's occupancy stays the space's
     * own, from every booking: a room others filled must not read as free
     * because the desk is looking for one member.
     *
     * @param  array{view?: ?string, date?: ?string, branch_id?: ?int, space_type?: ?string, space_id?: ?int, member_id?: ?int}  $filters
     * @return array<string, mixed>
     */
    public function build(array $filters, string $locale): array
    {
        $timezone = $this->time->timezone();
        $view = $filters['view'] ?? 'week';

        $anchor = isset($filters['date'])
            ? CarbonImmutable::parse($filters['date'], $timezone)->startOfDay()
            : CarbonImmutable::now($timezone)->startOfDay();

        [$from, $to] = $this->rangeFor($view, $anchor);

        $spaces = $this->spacesFor($filters);

        if ($spaces->isEmpty()) {
            return $this->empty($view, $from, $to, $anchor, $timezone, $filters);
        }

        $branch = $spaces->first()->building->branch;

        // One fetch for every space across the whole range, then grouped in
        // memory — not one query per space, and not one per day.
        $bookings = Booking::query()
            ->whereIn('space_id', $spaces->pluck('id'))
            // The shared scopes rather than their inlined equivalents, so
            // "which statuses hold a slot" and "what counts as an overlap"
            // stay defined in one place for the calendar too
            // (tests/Guards/AvailabilityRulesAreCentralizedTest).
            ->blocking()
            ->overlapping($from->utc(), $to->addDay()->utc())
            ->with('user:id,name,phone')
            ->orderBy('start_at')
            ->get()
            ->groupBy('space_id');

        // Two queries for the whole range, shared by every space in it —
        // hours belong to the branch, not the space.
        $periodsByDate = $this->businessHours->periodsForRange($from, $to, $branch);

        // A co_space's seats are its branch's pool, so its occupancy is the
        // pool's — read once for the range, whatever the number of rows
        // (docs/decisions/coworking-seat-pool.md).
        $pool = $spaces->contains(fn (Space $space) => $this->availability->usesBranchPool($space))
            ? $this->availability->poolTimeline($spaces->first(fn (Space $space) => $this->availability->usesBranchPool($space)), $from->utc(), $to->addDay()->utc())
            : null;

        // The offices a company leases over the range, in one query: a lease
        // day is the tenant's, never a free day (docs/decisions/office-leases.md).
        $leases = $this->availability
            ->blockingLeases($spaces->pluck('id'), $from->utc(), $to->addDay()->utc(), $timezone);
        // Named on the calendar's leases view: whose office it is.
        EloquentCollection::make($leases->all())->load('company:id,legal_name');
        $leases = $leases->groupBy('space_id');

        $memberId = isset($filters['member_id']) ? (int) $filters['member_id'] : null;

        $resources = $spaces
            ->map(fn (Space $space) => $this->resourceRow(
                $space,
                $bookings->get($space->id) ?? collect(),
                $periodsByDate,
                $from,
                $to,
                $locale,
                $this->availability->usesBranchPool($space) ? $pool : null,
                $memberId,
                $leases->get($space->id) ?? collect(),
            ))
            ->values()
            ->all();

        $listed = $memberId === null
            ? $bookings
            : $bookings->map(fn (Collection $rows) => $rows->where('user_id', $memberId)->values());

        $rules = $this->availability->rulesFor($spaces->first());

        return [
            'view' => $view,
            'range' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'timezone' => $timezone,
            ],
            'branch_hours' => $this->presenter->branchHours(
                $branch,
                $periodsByDate[$anchor->format('Y-m-d')] ?? [],
                // The ordinary week, from the schedule itself — not the days
                // in view, which in a day view named one weekday as the week.
                $this->businessHours->weeklySchedule($branch),
                $rules,
                $locale,
                $this->businessHours->upcomingClosures($branch),
            ),
            // The desk's booking setup, read once with the calendar as the
            // member app reads its rules once from the space details: the
            // shortest run the booking form accepts, and the window its date
            // picker refuses dates beyond before asking for any slots. The
            // day's slots carry none of it. All global settings, one block
            // per response.
            'booking_window' => [
                'min_duration_minutes' => $rules->minDurationMinutes,
                'min_notice_minutes' => $rules->minNoticeMinutes,
                'max_days_ahead' => $rules->maxDaysAhead,
            ],
            'resources' => $resources,
            'totals' => $this->totals($resources, $listed),
            'meta' => [
                'view' => $view,
                'date' => $anchor->format('Y-m-d'),
                'branch_id' => $branch->id,
                'member_id' => $memberId,
                'spaces' => count($resources),
            ],
        ];
    }

    /**
     * One space's row: the space, then a cell per day.
     *
     * @param  Collection<int, Booking>  $bookings
     * @param  array<string, array<int, array{open_time: string, close_time: string}>>  $periodsByDate
     * @param  Collection<int, OfficeLease>|null  $leases
     * @return array<string, mixed>
     */
    private function resourceRow(
        Space $space,
        Collection $bookings,
        array $periodsByDate,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $locale,
        ?PoolTimeline $pool = null,
        ?int $memberId = null,
        ?Collection $leases = null,
    ): array {
        $rules = $this->availability->rulesFor($space);
        $days = [];

        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            $key = $date->format('Y-m-d');
            $periods = $periodsByDate[$key] ?? [];

            $dayStart = $date->utc();
            $dayEnd = $date->addDay()->utc();

            // In-memory rather than a query per day, and through the
            // engine's own predicate rather than a hand-rolled comparison.
            $onThisDay = $bookings->filter(
                fn (Booking $booking) => $this->availability->overlapCount(
                    collect([$booking]),
                    $dayStart,
                    $dayEnd,
                ) > 0
            )->values();

            $days[] = [
                'date' => $key,
                'weekday' => strtoupper($date->format('D')),
                'day' => $date->format('d'),
                // `leased`: a company holds the office all day, so the desk
                // cannot book it whatever the hours say.
                'state' => match (true) {
                    $periods === [] => 'closed',
                    $this->availability->isHeldByLease($leases ?? collect(), $dayStart, $dayEnd, $rules->timezone) => 'leased',
                    default => 'open',
                },
                'periods' => $periods,
                'occupancy' => $pool !== null
                    ? $this->poolOccupancy($pool, $periods, $date)
                    : $this->meter->day($onThisDay, $periods, $date, $rules->concurrencyCeiling),
                // Occupancy above counts every booking; the list is the
                // member's alone when the calendar is narrowed to one.
                'bookings' => $onThisDay
                    ->when($memberId !== null, fn (Collection $rows) => $rows->where('user_id', $memberId))
                    ->map(fn (Booking $booking) => $this->bookingCell($booking, $locale))
                    ->values()
                    ->all(),
            ];
        }

        return [
            'space' => [
                'id' => $space->id,
                'title' => $space->translate('name', $locale),
                'space_type' => $space->space_type->value,
                'capacity' => $space->capacity,
                'concurrency_ceiling' => $rules->concurrencyCeiling,
                'status' => $space->status->value,
                'requires_approval' => $rules->requiresApproval,
            ],
            'days' => $days,
            // The leases holding this office over the range, whole: the
            // calendar draws each as one bar on its own view, apart from the
            // bookings, instead of a "leased" mark on every day.
            'leases' => ($leases ?? collect())
                ->sortBy('starts_on')
                ->map(fn (OfficeLease $lease) => [
                    'id' => $lease->id,
                    'company' => $lease->company?->legal_name,
                    'starts_on' => $lease->starts_on->format('Y-m-d'),
                    'ends_on' => $lease->accessEndsOn()->format('Y-m-d'),
                    'status' => $lease->status->value,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * How much of the co-working pool's open day is taken: seat-minutes
     * held or occupied over seat-minutes open, inside the day's open
     * periods. Subscriptions holding seats, bookings and the walk-ins inside
     * now all count, as they do for every pool decision.
     *
     * `booked_minutes` / `open_minutes` keep their names for the grid, as
     * seat-minutes here.
     *
     * @param  array<int, array{open_time: string, close_time: string}>  $periods
     * @return array<string, int>
     */
    private function poolOccupancy(PoolTimeline $pool, array $periods, CarbonImmutable $date): array
    {
        $taken = 0;
        $open = 0;

        foreach ($periods as $period) {
            $periodStart = CarbonImmutable::parse($date->format('Y-m-d').' '.$period['open_time'], $date->timezone)->utc();
            $periodEnd = CarbonImmutable::parse($date->format('Y-m-d').' '.$period['close_time'], $date->timezone)->utc();

            foreach ($pool->segments() as $segment) {
                $start = max($segment['start'], $periodStart);
                $end = min($segment['end'], $periodEnd);

                if ($start->lessThan($end)) {
                    $minutes = (int) $start->diffInMinutes($end);
                    $open += $minutes * $segment['capacity'];
                    $taken += $minutes * min($segment['usage'], $segment['capacity']);
                }
            }
        }

        return [
            'booked_minutes' => $taken,
            'open_minutes' => $open,
            'percent' => $open === 0 ? 0 : (int) min(100, round($taken / $open * 100)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingCell(Booking $booking, string $locale): array
    {
        $start = $this->time->toLocal($booking->start_at);
        $end = $this->time->toLocal($booking->end_at);

        return [
            'id' => $booking->id,
            'start_at' => $start->toIso8601String(),
            'end_at' => $end->toIso8601String(),
            'start_time' => $start->format('h:i A'),
            'end_time' => $end->format('h:i A'),
            'status' => $booking->status->value,
            'status_label' => __('api.booking.statuses.'.$booking->status->value, [], $locale),
            'payment_state' => $booking->payment_state->value,
            'checked_in_at' => $booking->checked_in_at?->toIso8601String(),
            // When the member left; null while still inside or never arrived.
            'checked_out_at' => $booking->checked_out_at?->toIso8601String(),
            'member' => [
                'id' => $booking->user?->id,
                'name' => $booking->user?->name,
                'phone' => $booking->user?->phone,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $resources
     * @param  Collection<int, Collection<int, Booking>>  $bookings
     * @return array<string, mixed>
     */
    private function totals(array $resources, Collection $bookings): array
    {
        $all = $bookings->flatten(1);
        $percents = collect($resources)
            ->flatMap(fn (array $row) => collect($row['days'])->pluck('occupancy.percent'));

        return [
            'spaces' => count($resources),
            'bookings' => $all->count(),
            'occupancy_percent' => $percents->isEmpty() ? 0 : (int) round($percents->avg()),
            'by_status' => $all->groupBy(fn (Booking $booking) => $booking->status->value)
                ->map(fn (Collection $rows) => $rows->count())
                ->all(),
        ];
    }

    /**
     * @return Collection<int, Space>
     */
    private function spacesFor(array $filters): Collection
    {
        return Space::query()
            ->where('status', OperationalStatus::Active)
            ->when($filters['space_id'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->when($filters['space_type'] ?? null, fn ($query, $type) => $query->where('space_type', $type))
            ->when(
                $filters['branch_id'] ?? null,
                fn ($query, $branchId) => $query->whereHas('building', fn ($b) => $b->where('branch_id', $branchId)),
                fn ($query) => $query->whereHas('building', fn ($b) => $b->where('branch_id', Branch::defaultId()))
            )
            ->with(['building.branch', 'zone.floor'])
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function rangeFor(string $view, CarbonImmutable $anchor): array
    {
        return match ($view) {
            'day' => [$anchor, $anchor],
            'month' => [$anchor->startOfMonth(), $anchor->endOfMonth()->startOfDay()],
            // The week runs Sunday to Saturday, matching the district's
            // working week rather than the ISO Monday start.
            default => [
                $anchor->startOfWeek(CarbonImmutable::SUNDAY),
                $anchor->startOfWeek(CarbonImmutable::SUNDAY)->addDays(6),
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function empty(string $view, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $anchor, string $timezone, array $filters): array
    {
        return [
            'view' => $view,
            'range' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'timezone' => $timezone],
            'branch_hours' => null,
            'booking_window' => null,
            'resources' => [],
            'totals' => ['spaces' => 0, 'bookings' => 0, 'occupancy_percent' => 0, 'by_status' => []],
            'meta' => [
                'view' => $view,
                'date' => $anchor->format('Y-m-d'),
                'branch_id' => $filters['branch_id'] ?? null,
                'member_id' => isset($filters['member_id']) ? (int) $filters['member_id'] : null,
                'spaces' => 0,
            ],
        ];
    }
}
