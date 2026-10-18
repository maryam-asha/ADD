<?php

namespace Add\Hr\Integrations;

use Add\Hr\Contracts\HrClock;

/**
 * The default: hr.timezone, else the application's timezone.
 */
class ConfigHrClock implements HrClock
{
    public function timezone(): string
    {
        return (string) (config('hr.timezone') ?: config('app.timezone', 'UTC'));
    }
}
