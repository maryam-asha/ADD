<?php

namespace Add\Hr\Support;

use Add\Hr\Contracts\HrClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A day of the organisation's calendar, in the shape DateOnly reads a
 * stored one: midnight in the application's timezone. The organisation's
 * own midnight (`now($tz)->startOfDay()`) is another instant — 21:00 UTC
 * the day before at +03:00 — so `gt`, `lte` or `diffInDays` against a
 * DateOnly attribute would be a day off all day long.
 */
final class LocalDay
{
    /**
     * The organisation's today. Never `CarbonImmutable::today()`: that is
     * the application's (UTC) day, still yesterday from local midnight
     * until 03:00 at +03:00.
     */
    public static function today(?HrClock $clock = null): CarbonImmutable
    {
        return self::of(CarbonImmutable::now(($clock ?? app(HrClock::class))->timezone()));
    }

    /**
     * The calendar day `$day` shows in its own timezone, time and zone
     * dropped.
     */
    public static function of(CarbonInterface $day): CarbonImmutable
    {
        return CarbonImmutable::parse($day->toDateString());
    }
}
