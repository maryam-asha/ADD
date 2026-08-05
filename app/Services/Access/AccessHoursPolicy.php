<?php

namespace App\Services\Access;

use Carbon\CarbonImmutable;

/**
 * PRD 4.1 — provisional window (08:00-23:00 by default). Not wired to any
 * endpoint yet: intended for Sprint 3/4 check-in and booking flows once
 * they exist, so that time-window logic lives in exactly one place.
 */
class AccessHoursPolicy
{
    public function isWithinAllowedHours(?CarbonImmutable $at = null, ?string $timezone = null): bool
    {
        $timezone ??= config('app.timezone');
        $at = ($at ?? CarbonImmutable::now())->setTimezone($timezone);

        $start = CarbonImmutable::parse(config('access.allowed_hours.start'), $timezone)
            ->setDate($at->year, $at->month, $at->day);
        $end = CarbonImmutable::parse(config('access.allowed_hours.end'), $timezone)
            ->setDate($at->year, $at->month, $at->day);

        return $at->betweenIncluded($start, $end);
    }
}
