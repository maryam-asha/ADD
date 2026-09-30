<?php

namespace App\Support\Presenters;

use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * How a branch's hours are written out: the ordinary week grouped by the
 * hours its days share, the names of those days, a period's hours, and the
 * timezone label.
 *
 * One formatter for every surface that states a branch's hours — the member
 * spaces list's `working_hours_banner`, where the member app reads them once
 * for every space, and the `branch_hours` card on the operations calendar and
 * the member month calendar — so no two of them can word the same week
 * differently.
 *
 * It takes a timezone string rather than any booking object, so the
 * Foundation spaces list can use it without reaching into the Booking domain.
 */
class BranchHoursPresenter
{
    /**
     * The week as the dashboard's business-hours page orders it, Saturday
     * first, so the days a label names and the order it names them in read
     * the same on both surfaces.
     */
    public const WEEK = ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

    /**
     * The ordinary week: its days grouped by the hours they share, the open
     * days in week order, and a label naming them.
     *
     * A branch keeps different hours on different days, so one date's hours
     * next to "Saturday – Friday" would read "every day, 08:00–22:00" of a
     * week where Sunday and Friday differ; `schedule` gives each group its own.
     *
     * @param  array<string, list<array{open_time: string, close_time: string}>>  $weeklySchedule  BusinessHoursService::weeklySchedule()
     * @return array{schedule: list<array<string, mixed>>, days_label: string|null, open_weekdays: list<string>}
     */
    public function week(array $weeklySchedule, string $timezone, string $locale): array
    {
        $openWeekdays = array_values(array_filter(
            self::WEEK,
            fn (string $day) => ($weeklySchedule[$day] ?? []) !== [],
        ));

        return [
            'schedule' => $this->schedule($weeklySchedule, $timezone, $locale),
            'days_label' => $this->daysLabel($openWeekdays, $locale),
            'open_weekdays' => $openWeekdays,
        ];
    }

    /**
     * "8:00 AM – 10:00 PM", or one range per period for a split day.
     *
     * @param  array<int, array{open_time: string, close_time: string}>  $periods
     */
    public function hoursLabel(array $periods, string $timezone): string
    {
        return implode(', ', array_map(fn (array $period) => sprintf(
            '%s – %s',
            Carbon::parse($period['open_time'], $timezone)->format('g:i A'),
            Carbon::parse($period['close_time'], $timezone)->format('g:i A'),
        ), $periods));
    }

    /**
     * Derived at request time, never stored: Damascus observes DST, so a
     * hardcoded "GMT+3" would be wrong for part of the year.
     */
    public function timezoneLabel(string $timezone): string
    {
        $offset = CarbonImmutable::now($timezone)->format('P');
        [$hours, $minutes] = explode(':', $offset);

        $sign = str_starts_with($hours, '-') ? '-' : '+';
        $hour = (int) ltrim($hours, '+-');

        return $minutes === '00'
            ? sprintf('GMT%s%d', $sign, $hour)
            : sprintf('GMT%s%d:%s', $sign, $hour, $minutes);
    }

    /**
     * The open weekdays grouped by the hours they share, in week order —
     * a group per distinct set of periods, ordered by its first day.
     *
     * @param  array<string, list<array{open_time: string, close_time: string}>>  $weeklySchedule
     * @return list<array{days: list<string>, days_label: string|null, periods: list<array{open_time: string, close_time: string}>, hours: string}>
     */
    private function schedule(array $weeklySchedule, string $timezone, string $locale): array
    {
        $groups = [];

        foreach (self::WEEK as $day) {
            $periods = $weeklySchedule[$day] ?? [];

            if ($periods === []) {
                continue;
            }

            $key = json_encode($periods);
            $groups[$key] ??= ['days' => [], 'periods' => $periods];
            $groups[$key]['days'][] = $day;
        }

        return array_values(array_map(fn (array $group) => [
            'days' => $group['days'],
            'days_label' => $this->daysLabel($group['days'], $locale),
            'periods' => $group['periods'],
            'hours' => $this->hoursLabel($group['periods'], $timezone),
        ], $groups));
    }

    /**
     * Names days the way the dashboard does: each unbroken run in week order
     * as "first – last", single days by name, the runs joined — so Saturday
     * plus Monday–Thursday reads "Saturday, Monday – Thursday", and a branch
     * open Sunday, Tuesday and Thursday is never called "Sunday – Thursday".
     * Translated, with the locale's own separator.
     *
     * @param  list<string>  $days
     */
    private function daysLabel(array $days, string $locale): ?string
    {
        $indexes = array_values(array_filter(
            array_map(fn (string $day) => array_search($day, self::WEEK, true), $days),
            fn ($index) => $index !== false,
        ));

        if ($indexes === []) {
            return null;
        }

        sort($indexes);

        $runs = [[$indexes[0]]];

        foreach (array_slice($indexes, 1) as $index) {
            $last = &$runs[count($runs) - 1];

            if ($index === end($last) + 1) {
                $last[] = $index;
            } else {
                $runs[] = [$index];
            }

            unset($last);
        }

        $name = fn (int $index) => __('api.weekdays.'.self::WEEK[$index], [], $locale);

        return implode($locale === 'ar' ? '، ' : ', ', array_map(
            fn (array $run) => count($run) === 1
                ? $name($run[0])
                : sprintf('%s – %s', $name($run[0]), $name(end($run))),
            $runs,
        ));
    }
}
