<?php

namespace App\Domain\Foundation\Services;

use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\BusinessHourException;
use App\Domain\Settings\Services\SettingService;
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

        $exceptions = BusinessHourException::query()
            ->where('branch_id', $branch->id)
            ->whereDate('date', $local->toDateString())
            ->get();

        if ($exceptions->isNotEmpty()) {
            if ($exceptions->contains('is_closed', true)) {
                return [];
            }

            return $exceptions
                ->sortBy('open_time')
                ->values()
                ->map(fn (BusinessHourException $exception) => [
                    'open_time' => $exception->open_time,
                    'close_time' => $exception->close_time,
                ])
                ->all();
        }

        $weekday = DayOfWeek::fromCarbon($local);

        return BusinessHour::query()
            ->where('branch_id', $branch->id)
            ->where('day_of_week', $weekday)
            ->orderBy('open_time')
            ->get(['open_time', 'close_time'])
            ->map(fn (BusinessHour $businessHour) => [
                'open_time' => $businessHour->open_time,
                'close_time' => $businessHour->close_time,
            ])
            ->all();
    }

    private function toLocal(CarbonInterface $instant): CarbonInterface
    {
        return $instant->clone()->setTimezone(
            $this->settings->get('app.timezone', 'Asia/Damascus')
        );
    }
}
