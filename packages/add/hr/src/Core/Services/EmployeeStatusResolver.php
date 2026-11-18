<?php

namespace Add\Hr\Core\Services;

use Add\Hr\Core\Enums\EmployeeStatus;
use Add\Hr\Core\Enums\EmploymentEventType;
use Add\Hr\Core\Models\Employee;
use Carbon\CarbonInterface;

/**
 * The one definition of an employee's status on a day, from their dates
 * and events. The stored `status` column only caches this.
 */
class EmployeeStatusResolver
{
    public function resolve(Employee $employee, CarbonInterface $on): EmployeeStatus
    {
        $day = $on->toDateString();

        if ($employee->hired_on->toDateString() > $day) {
            return EmployeeStatus::Upcoming;
        }

        if ($employee->terminated_on !== null && $employee->terminated_on->toDateString() < $day) {
            return EmployeeStatus::Terminated;
        }

        $suspended = $employee->events()
            ->where('type', EmploymentEventType::Suspension->value)
            ->where('effective_on', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $day))
            ->exists();

        if ($suspended) {
            return EmployeeStatus::Suspended;
        }

        if ($employee->probation_ends_on !== null && $employee->confirmed_on === null) {
            return EmployeeStatus::Probation;
        }

        return EmployeeStatus::Active;
    }
}
