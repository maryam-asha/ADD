<?php

namespace Add\Hr\Support;

use Carbon\CarbonInterface;

/**
 * Instants as Eloquent stores them: in the application's timezone. Use it
 * for raw where() comparisons on datetime columns, so they match what the
 * casts wrote whatever the host's timezone is.
 */
final class Instant
{
    public static function db(CarbonInterface $instant): string
    {
        return $instant->copy()->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
    }
}
