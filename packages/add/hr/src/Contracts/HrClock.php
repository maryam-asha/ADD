<?php

namespace Add\Hr\Contracts;

/**
 * The organisation's local timezone. Shifts, work dates and "today" for
 * attendance are local; instants are stored in UTC.
 */
interface HrClock
{
    public function timezone(): string;
}
