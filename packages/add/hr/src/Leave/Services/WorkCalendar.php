<?php

namespace Add\Hr\Leave\Services;

use Add\Hr\Leave\Models\Holiday;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Which days are working days: not a weekend day (hr.leave.weekend_days)
 * and not a public holiday. Per-employee work patterns arrive with time
 * and attendance; until then the calendar is the same for everyone.
 */
class WorkCalendar
{
    /**
     * @return list<CarbonImmutable>
     */
    public function workingDays(CarbonInterface $from, CarbonInterface $to): array
    {
        $holidays = $this->holidaysBetween($from, $to);

        return array_values(array_filter(
            $this->days($from, $to),
            fn (CarbonImmutable $day) => $this->isWorkingDayGiven($day, $holidays),
        ));
    }

    public function isWorkingDay(CarbonInterface $day): bool
    {
        return $this->isWorkingDayGiven(CarbonImmutable::parse($day), $this->holidaysBetween($day, $day));
    }

    /**
     * @return list<CarbonImmutable>
     */
    public function days(CarbonInterface $from, CarbonInterface $to): array
    {
        return array_map(
            fn ($day) => CarbonImmutable::parse($day)->startOfDay(),
            iterator_to_array(CarbonPeriod::create($from->toDateString(), $to->toDateString())),
        );
    }

    /**
     * @param  array<string, true>  $holidays
     */
    private function isWorkingDayGiven(CarbonImmutable $day, array $holidays): bool
    {
        return ! in_array($day->isoWeekday(), array_map('intval', (array) config('hr.leave.weekend_days', [])), true)
            && ! isset($holidays[$day->toDateString()]);
    }

    /**
     * Every holiday day in the range, keyed `Y-m-d`; a recurring holiday
     * counts in every year the range touches.
     *
     * @return array<string, true>
     */
    private function holidaysBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        $start = $from->toDateString();
        $end = $to->toDateString();
        $days = [];

        /** @var Collection<int, Holiday> $holidays */
        $holidays = Holiday::query()
            ->where(fn ($q) => $q->where('recurs_annually', true)
                ->orWhere(fn ($q) => $q->where('starts_on', '<=', $end)->where('ends_on', '>=', $start)))
            ->get();

        foreach ($holidays as $holiday) {
            $years = $holiday->recurs_annually ? range($from->year, $to->year) : [null];

            foreach ($years as $year) {
                $first = $year === null ? $holiday->starts_on : $holiday->starts_on->setYear($year);
                $last = $year === null ? $holiday->ends_on : $first->addDays((int) $holiday->starts_on->diffInDays($holiday->ends_on));

                foreach ($this->days($first, $last) as $day) {
                    $key = $day->toDateString();

                    if ($key >= $start && $key <= $end) {
                        $days[$key] = true;
                    }
                }
            }
        }

        return $days;
    }
}
