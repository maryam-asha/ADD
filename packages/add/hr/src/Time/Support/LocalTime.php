<?php

namespace Add\Hr\Time\Support;

use Add\Hr\Contracts\HrClock;
use Carbon\CarbonInterface;

/**
 * Instants leave the API in the organisation's timezone, with its offset
 * (2026-03-02T08:05:00+03:00), so a client reads them as wall-clock time.
 */
final class LocalTime
{
    public static function iso(?CarbonInterface $instant): ?string
    {
        return $instant?->copy()->setTimezone(app(HrClock::class)->timezone())->toIso8601String();
    }
}
