<?php

namespace Add\Hr\Leave\Listeners;

use Add\Hr\Core\Events\EmployeeTerminated;
use Add\Hr\Leave\Services\LeaveService;

/**
 * A termination withdraws pending leave and cancels approved leave that
 * would start after the last working day. Inside the termination's
 * transaction.
 */
class CancelLeaveAfterTermination
{
    public function __construct(private readonly LeaveService $leave) {}

    public function handle(EmployeeTerminated $event): void
    {
        $this->leave->cancelAfterTermination($event->employee);
    }
}
