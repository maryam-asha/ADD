<?php

namespace Add\Hr\Core\Enums;

/**
 * Where an employment stands on a given day. Derived from the employee's
 * dates and events by EmployeeStatusResolver; the stored column is a cache
 * for filtering, refreshed by every lifecycle event and daily.
 */
enum EmployeeStatus: string
{
    case Upcoming = 'upcoming';
    case Probation = 'probation';
    case Active = 'active';
    case Suspended = 'suspended';
    case Terminated = 'terminated';
}
