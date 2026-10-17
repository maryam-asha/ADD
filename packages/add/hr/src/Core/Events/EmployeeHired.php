<?php

namespace Add\Hr\Core\Events;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Models\EmploymentEvent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A hire or a rehire was recorded. Dispatched inside its transaction, so
 * listeners' writes commit or roll back with it.
 */
class EmployeeHired
{
    use Dispatchable;

    public function __construct(
        public readonly Employee $employee,
        public readonly EmploymentEvent $event,
    ) {}
}
