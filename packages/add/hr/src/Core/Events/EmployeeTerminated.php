<?php

namespace Add\Hr\Core\Events;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Models\EmploymentEvent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A termination was recorded (its last day may still be ahead). Dispatched
 * inside its transaction.
 */
class EmployeeTerminated
{
    use Dispatchable;

    public function __construct(
        public readonly Employee $employee,
        public readonly EmploymentEvent $event,
    ) {}
}
