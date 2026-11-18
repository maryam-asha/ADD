<?php

namespace Add\Hr\Tests\Concerns;

use Carbon\CarbonImmutable;

/**
 * The application runs in UTC and the organisation at +03:00
 * (Asia/Damascus), so from 00:00 to 03:00 on the organisation's clock the
 * UTC day is still yesterday.
 */
trait TravelsOnTheOrganisationsClock
{
    /**
     * Travel to a moment read off the organisation's wall clock.
     */
    protected function travelToLocal(string $wallClock): void
    {
        config(['hr.timezone' => 'Asia/Damascus']);
        $this->travelTo(CarbonImmutable::parse($wallClock, 'Asia/Damascus')->utc());
    }
}
