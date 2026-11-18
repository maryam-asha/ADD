<?php

namespace Add\Hr\Timesheets\Services;

use Add\Hr\Support\HrException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Timesheet weeks start on hr.timesheets.week_starts_on (ISO weekday).
 */
class TimesheetWeeks
{
    public static function startOf(CarbonInterface $day): CarbonImmutable
    {
        $day = CarbonImmutable::parse($day->toDateString());
        $back = ($day->isoWeekday() - self::firstDay() + 7) % 7;

        return $day->subDays($back);
    }

    /**
     * A week given by its first day; any other day is refused.
     */
    public static function parse(string $weekStart): CarbonImmutable
    {
        $day = CarbonImmutable::parse($weekStart);

        if (! $day->equalTo(self::startOf($day))) {
            HrException::fail('hr::timesheets.not_a_week_start');
        }

        return $day;
    }

    private static function firstDay(): int
    {
        return (int) config('hr.timesheets.week_starts_on', 1);
    }
}
