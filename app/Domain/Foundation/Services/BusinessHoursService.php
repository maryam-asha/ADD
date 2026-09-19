<?php

namespace App\Domain\Foundation\Services;

use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\BusinessHourException;
use App\Domain\Settings\Services\SettingService;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Answers "is this instant within business hours for this branch" and
 * "what are the open/close periods for this branch on this date." This is
 * the ONLY place resolution order is decided: an exception for the date,
 * if any, fully replaces the weekly schedule (it does not merge with it);
 * otherwise the weekday's schedule rows apply; no rows at either level
 * means closed. Both `open_time` and `close_time` are inclusive boundaries
 * (docs/decisions/business-hours.md) — an instant exactly at either edge
 * counts as within business hours.
 *
 * All comparisons resolve through a single global `app.timezone` Setting,
 * not a per-branch column — `branches.timezone` is a separate, unrelated,
 * pre-existing column this service does not read.
 */
class BusinessHoursService
{
    public function __construct(private readonly SettingService $settings) {}

    public function isWithinBusinessHours(CarbonInterface $instant, Branch $branch): bool
    {
        $local = $this->toLocal($instant);
        $time = $local->format('H:i');

        foreach ($this->periodsFor($local, $branch) as $period) {
            if ($time >= $period['open_time'] && $time <= $period['close_time']) {
                return true;
            }
        }

        return false;
    }

    /**
     * An empty array means closed, but does not distinguish an explicit
     * exception closure (which may carry a `reason` on the
     * `BusinessHourException` row) from a weekday with no schedule at all —
     * a caller that needs to explain *why* a date is closed must query
     * `BusinessHourException` directly for that date.
     *
     * @return array<int, array{open_time: string, close_time: string}>
     */
    public function periodsFor(CarbonInterface $date, Branch $branch): array
    {
        $local = $this->toLocal($date);

        return $this->periodsForRange($local, $local, $branch)[$local->toDateString()] ?? [];
    }

    /**
     * The same resolution, for every date in a range, in two queries.
     *
     * The availability calendar asks this question 31 times per space;
     * calling periodsFor() in a loop meant 62 queries for one month. The
     * resolution order is not duplicated here — periodsFor() is now a
     * one-day call to this method, so there remains exactly one
     * implementation of "an exception replaces the weekly schedule."
     *
     * Both bounds are inclusive.
     *
     * @return array<string, array<int, array{open_time: string, close_time: string}>>
     */
    public function periodsForRange(CarbonInterface $from, CarbonInterface $to, Branch $branch): array
    {
        $localFrom = $this->toLocal($from)->startOfDay();
        $localTo = $this->toLocal($to)->startOfDay();

        // whereDate on both bounds rather than whereBetween: `date` is a
        // DATE column that some drivers hand back as "Y-m-d 00:00:00", and a
        // plain string range then excludes the final day (and, in SQLite,
        // the first). whereDate normalises both sides the way the original
        // single-date lookup did.
        $exceptionsByDate = BusinessHourException::query()
            ->where('branch_id', $branch->id)
            ->whereDate('date', '>=', $localFrom->toDateString())
            ->whereDate('date', '<=', $localTo->toDateString())
            ->get()
            ->groupBy(fn (BusinessHourException $exception) => Carbon::parse($exception->date)->toDateString());

        $scheduleByWeekday = BusinessHour::query()
            ->where('branch_id', $branch->id)
            ->orderBy('open_time')
            ->get(['day_of_week', 'open_time', 'close_time'])
            ->groupBy(fn (BusinessHour $businessHour) => $businessHour->day_of_week instanceof DayOfWeek
                ? $businessHour->day_of_week->value
                : (string) $businessHour->day_of_week);

        $resolved = [];

        for ($date = $localFrom->copy(); $date->lessThanOrEqualTo($localTo); $date = $date->addDay()) {
            $key = $date->toDateString();
            $exceptions = $exceptionsByDate->get($key);

            if ($exceptions !== null && $exceptions->isNotEmpty()) {
                $resolved[$key] = $exceptions->contains('is_closed', true)
                    ? []
                    : $exceptions
                        ->sortBy('open_time')
                        ->values()
                        ->map(fn (BusinessHourException $exception) => [
                            'open_time' => $exception->open_time,
                            'close_time' => $exception->close_time,
                        ])
                        ->all();

                continue;
            }

            $weekday = DayOfWeek::fromCarbon($date);

            $resolved[$key] = ($scheduleByWeekday->get($weekday->value) ?? collect())
                ->sortBy('open_time')
                ->values()
                ->map(fn (BusinessHour $businessHour) => [
                    'open_time' => $businessHour->open_time,
                    'close_time' => $businessHour->close_time,
                ])
                ->all();
        }

        return $resolved;
    }

    private function toLocal(CarbonInterface $instant): CarbonInterface
    {
        return $instant->clone()->setTimezone(
            $this->settings->get('app.timezone', 'Asia/Damascus')
        );
    }
}
