<?php

namespace App\Domain\Hr\Adapters;

use Add\Hr\Contracts\HrClock;
use App\Domain\Settings\Services\SettingService;

/**
 * ADD's local timezone lives in the `app.timezone` setting (the app itself
 * runs in UTC), the same one bookings and business hours use.
 */
class AddHrClock implements HrClock
{
    public function __construct(private readonly SettingService $settings) {}

    public function timezone(): string
    {
        return (string) $this->settings->get('app.timezone', 'Asia/Damascus');
    }
}
