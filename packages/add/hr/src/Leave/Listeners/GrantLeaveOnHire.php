<?php

namespace Add\Hr\Leave\Listeners;

use Add\Hr\Core\Events\EmployeeHired;
use Add\Hr\Leave\Services\LeaveAccrual;
use Add\Hr\Support\LocalDay;

/**
 * A new (or rehired) employee gets what their leave policies already owe
 * them — the first yearly grant, under the policy's HireGrant — the moment
 * they are hired, not the next night. Inside the hire's transaction; the
 * nightly run finds it booked and adds nothing twice.
 */
class GrantLeaveOnHire
{
    public function __construct(private readonly LeaveAccrual $accrual) {}

    public function handle(EmployeeHired $event): void
    {
        $this->accrual->forEmployee($event->employee, LocalDay::today());
    }
}
