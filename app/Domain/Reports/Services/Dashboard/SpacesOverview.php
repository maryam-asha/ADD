<?php

namespace App\Domain\Reports\Services\Dashboard;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\AvailabilityService;
use App\Domain\Booking\Services\OccupancyMeter;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Space;
use App\Domain\Foundation\Services\BusinessHoursService;
use App\Domain\Reports\ValueObjects\DashboardPeriod;
use App\Support\PlainAmount;
use App\Support\Time\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The spaces section of the dashboard's home page: per bookable space, how
 * often and how long it was booked over the period, how full it ran, how
 * reliably people came, and when — the weekday × hour pattern of booked
 * time. A booking "held" its slot when its status holds one
 * (Booking::scopeBlocking); counts are by the day a booking starts,
 * occupancy and the pattern by the time it covers.
 *
 * Occupancy is OccupancyMeter's, the operations calendar's own figure,
 * summed over the period. A Co-Space has none here: its seats are the
 * branch's shared pool, which keeps no record of past walk-ins. Private
 * offices are left out — they are used through leases, not bookings.
 */
final class SpacesOverview
{
    public const TYPES = [SpaceType::Room, SpaceType::EventHall, SpaceType::CoSpace];

    public function __construct(
        private readonly OccupancyMeter $meter,
        private readonly BusinessHoursService $hours,
        private readonly AvailabilityService $availability,
        private readonly LocalTime $time,
    ) {}

    /** @return array<string, mixed> */
    public function for(DashboardPeriod $period, ?Branch $branch, ?SpaceType $type, ?Space $space): array
    {
        $spaces = $this->spaces($branch, $type, $space);
        $previous = $period->previous();
        $locale = app()->getLocale();
        $current = $this->measure($spaces, $period);

        return [
            'period' => $period->toArray(),
            'previous_period' => $previous->toArray(),
            'summary' => [
                'current' => $current['summary'],
                'previous' => $this->measure($spaces, $previous)['summary'],
            ],
            'spaces' => $spaces
                ->map(fn (Space $s) => [
                    'space' => [
                        'id' => $s->id,
                        'name' => $s->translate('name', $locale),
                        'type' => $s->space_type->value,
                        'branch' => ['id' => $s->building->branch->id, 'name' => $s->building->branch->translate('name', $locale)],
                    ],
                    ...$current['rows'][$s->id],
                ])
                ->sortByDesc('bookings')
                ->values()
                ->all(),
            'peak' => $current['peak'],
        ];
    }

    /** @return Collection<int, Space> */
    private function spaces(?Branch $branch, ?SpaceType $type, ?Space $space): Collection
    {
        return Space::query()
            ->where('status', OperationalStatus::Active)
            ->whereIn('space_type', $type !== null ? [$type] : self::TYPES)
            ->when($space, fn (Builder $q) => $q->whereKey($space->id))
            ->when($branch, fn (Builder $q) => $q->whereHas('building', fn (Builder $b) => $b->where('branch_id', $branch->id)))
            ->with('building.branch')
            ->orderBy('id')
            ->get();
    }

    /**
     * Every figure for the spaces over one period, from two queries: the
     * bookings starting in it by status, and the bookings holding a slot in
     * it with their times.
     *
     * @param  Collection<int, Space>  $spaces
     * @return array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>, peak: array<string, mixed>}
     */
    private function measure(Collection $spaces, DashboardPeriod $period): array
    {
        $ids = $spaces->modelKeys();

        $byStatus = Booking::query()
            ->whereIn('space_id', $ids)
            ->where('start_at', '>=', $period->start)
            ->where('start_at', '<', $period->end)
            ->selectRaw('space_id, status, COUNT(*) AS aggregate')
            ->groupBy('space_id', 'status')
            ->toBase()
            ->get()
            ->groupBy('space_id');

        $held = Booking::query()
            ->whereIn('space_id', $ids)
            ->blocking()
            ->overlapping($period->start, $period->end)
            ->get(['id', 'space_id', 'user_id', 'start_at', 'end_at', 'status'])
            ->groupBy('space_id');

        $days = $this->days($period);
        $openDaysOf = [];
        $periodsOf = [];
        $rows = [];
        $totals = ['booked' => 0, 'capacity' => 0];

        foreach ($spaces as $space) {
            $branch = $space->building->branch;
            $periodsOf[$branch->id] ??= $this->hours->periodsForRange($days[0], end($days), $branch);
            $openDaysOf[$branch->id] ??= count(array_filter($periodsOf[$branch->id]));

            $counts = ($byStatus->get($space->id) ?? collect())->pluck('aggregate', 'status')->map(fn ($c) => (int) $c);
            $bookings = $held->get($space->id) ?? collect();
            $starting = $bookings->filter(fn (Booking $b) => $b->start_at->greaterThanOrEqualTo($period->start) && $b->start_at->lessThan($period->end));
            $occupancy = $this->occupancy($space, $bookings, $periodsOf[$branch->id], $days);

            if ($occupancy['percent'] !== null) {
                $totals['booked'] += $occupancy['booked_minutes'];
                $totals['capacity'] += $occupancy['capacity_minutes'];
            }

            $rows[$space->id] = [
                ...$this->outcomes($counts, $starting->count()),
                'average_per_day' => $openDaysOf[$branch->id] === 0 ? null : $this->ratio($starting->count(), $openDaysOf[$branch->id]),
                'average_per_week' => $this->ratio($starting->count() * 7, count($days)),
                'average_duration_minutes' => $starting->isEmpty() ? null : (int) round($starting->avg(fn (Booking $b) => $b->start_at->diffInMinutes($b->end_at))),
                'booked_hours' => PlainAmount::of(bcdiv((string) $occupancy['booked_minutes'], '60', 2)),
                'occupancy_percent' => $occupancy['percent'],
                'members' => $starting->pluck('user_id')->unique()->count(),
            ];
        }

        $allCounts = $byStatus->flatten(1)->groupBy('status')->map(fn ($rows) => (int) $rows->sum('aggregate'));
        $allStarting = $held->flatten(1)->filter(fn (Booking $b) => $b->start_at->greaterThanOrEqualTo($period->start) && $b->start_at->lessThan($period->end));

        return [
            'rows' => $rows,
            'summary' => [
                ...$this->outcomes($allCounts, $allStarting->count()),
                'occupancy_percent' => $totals['capacity'] === 0 ? null : $this->ratio($totals['booked'] * 100, $totals['capacity']),
                'members' => $allStarting->pluck('user_id')->unique()->count(),
            ],
            'peak' => $this->peak($held->flatten(1), $period),
        ];
    }

    /**
     * Held bookings, and how the period's bookings ended. No-shows are
     * measured against the bookings that came due, cancellations against
     * every booking made — as in the operations section.
     *
     * @param  Collection<string, int>  $counts  bookings starting in the period, by status
     * @return array<string, int|string|null>
     */
    private function outcomes(Collection $counts, int $held): array
    {
        $completed = $counts[BookingStatus::Completed->value] ?? 0;
        $noShow = $counts[BookingStatus::NoShow->value] ?? 0;
        $cancelled = $counts[BookingStatus::Cancelled->value] ?? 0;
        $all = (int) $counts->sum();

        return [
            'bookings' => $held,
            'no_show_rate' => $completed + $noShow === 0 ? null : $this->ratio($noShow * 100, $completed + $noShow),
            'cancellation_rate' => $all === 0 ? null : $this->ratio($cancelled * 100, $all),
        ];
    }

    /**
     * The period's occupancy for one space: each day's OccupancyMeter figure,
     * summed, then booked over open-times-ceiling. Null for a Co-Space, and
     * for a branch with no open hours in the period.
     *
     * @param  Collection<int, Booking>  $bookings
     * @param  array<string, array<int, array{open_time: string, close_time: string}>>  $periodsByDate
     * @param  list<CarbonImmutable>  $days
     * @return array{booked_minutes: int, capacity_minutes: int, percent: ?string}
     */
    private function occupancy(Space $space, Collection $bookings, array $periodsByDate, array $days): array
    {
        $ceiling = $this->availability->rulesFor($space)->concurrencyCeiling;
        $byDay = $this->byLocalDay($bookings);
        $booked = 0;
        $open = 0;

        foreach ($days as $day) {
            $measured = $this->meter->day($byDay[$day->format('Y-m-d')] ?? [], $periodsByDate[$day->format('Y-m-d')] ?? [], $day, $ceiling);
            $booked += $measured['booked_minutes'];
            $open += $measured['open_minutes'];
        }

        $capacity = $open * max(1, $ceiling);
        $pooled = $this->availability->usesBranchPool($space);
        // Never past full, as each day is capped (OccupancyMeter).
        $booked = min($booked, $capacity);

        return [
            'booked_minutes' => $booked,
            'capacity_minutes' => $capacity,
            'percent' => $pooled || $capacity === 0 ? null : $this->ratio($booked * 100, $capacity),
        ];
    }

    /**
     * Each booking under every local day it touches, so a day's measure
     * reads only that day's bookings.
     *
     * @param  Collection<int, Booking>  $bookings
     * @return array<string, list<Booking>>
     */
    private function byLocalDay(Collection $bookings): array
    {
        $timezone = $this->time->timezone();
        $byDay = [];

        foreach ($bookings as $booking) {
            $day = CarbonImmutable::instance($booking->start_at)->setTimezone($timezone)->startOfDay();
            $last = CarbonImmutable::instance($booking->end_at)->setTimezone($timezone);

            for (; $day->lessThan($last); $day = $day->addDay()) {
                $byDay[$day->format('Y-m-d')][] = $booking;
            }
        }

        return $byDay;
    }

    /**
     * Booked time by local weekday (0 = Sunday) and hour, in minutes, inside
     * the period — when the spaces are wanted — with the busiest of each.
     *
     * @param  Collection<int, Booking>  $bookings
     * @return array{minutes: list<list<int>>, busiest_weekday: ?int, busiest_hour: ?int}
     */
    private function peak(Collection $bookings, DashboardPeriod $period): array
    {
        $timezone = $this->time->timezone();
        $grid = array_fill(0, 7, array_fill(0, 24, 0));

        foreach ($bookings as $booking) {
            $from = CarbonImmutable::instance(max($booking->start_at, $period->start))->setTimezone($timezone);
            $to = CarbonImmutable::instance(min($booking->end_at, $period->end))->setTimezone($timezone);

            while ($from->lessThan($to)) {
                $hourEnd = $from->startOfHour()->addHour();
                $until = $hourEnd->lessThan($to) ? $hourEnd : $to;
                $grid[$from->dayOfWeek][$from->hour] += (int) $from->diffInMinutes($until);
                $from = $until;
            }
        }

        $byWeekday = array_map('array_sum', $grid);
        $byHour = array_map(fn (int $hour) => array_sum(array_column($grid, $hour)), range(0, 23));
        $empty = array_sum($byWeekday) === 0;

        return [
            'minutes' => $grid,
            'busiest_weekday' => $empty ? null : (int) array_search(max($byWeekday), $byWeekday, true),
            'busiest_hour' => $empty ? null : (int) array_search(max($byHour), $byHour, true),
        ];
    }

    /** @return list<CarbonImmutable> the period's local days */
    private function days(DashboardPeriod $period): array
    {
        $timezone = $this->time->timezone();
        $days = [];

        for ($day = CarbonImmutable::parse($period->from, $timezone); $day->format('Y-m-d') <= $period->to; $day = $day->addDay()) {
            $days[] = $day;
        }

        return $days;
    }

    private function ratio(int $part, int $whole): string
    {
        return PlainAmount::of(bcdiv((string) $part, (string) $whole, 2));
    }
}
