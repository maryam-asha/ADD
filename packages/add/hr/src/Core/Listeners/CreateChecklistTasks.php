<?php

namespace Add\Hr\Core\Listeners;

use Add\Hr\Core\Enums\ChecklistKind;
use Add\Hr\Core\Events\EmployeeHired;
use Add\Hr\Core\Events\EmployeeTerminated;
use Add\Hr\Core\Services\ChecklistService;

/**
 * Onboarding tasks on every hire and rehire, offboarding tasks on every
 * termination. Synchronous, inside the event's transaction.
 */
class CreateChecklistTasks
{
    public function __construct(private readonly ChecklistService $checklists) {}

    public function handle(EmployeeHired|EmployeeTerminated $event): void
    {
        $kind = $event instanceof EmployeeHired ? ChecklistKind::Onboarding : ChecklistKind::Offboarding;

        $this->checklists->createFor($event->employee, $kind, $event->event);
    }
}
