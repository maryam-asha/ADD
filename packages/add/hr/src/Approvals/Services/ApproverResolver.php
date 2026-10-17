<?php

namespace Add\Hr\Approvals\Services;

use Add\Hr\Approvals\Enums\ApproverType;
use Add\Hr\Approvals\Models\ApprovalRequest;
use Add\Hr\Approvals\Models\ApprovalStep;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Models\OrgUnit;
use Carbon\CarbonImmutable;

/**
 * Turns a step's approver type into who decides it today. A step that
 * would land on nobody, on someone no longer employed, or on the requester
 * goes to the holders of the fallback ability instead — a request never
 * approves itself for lack of an approver.
 */
class ApproverResolver
{
    /**
     * Sets exactly one of approver_employee_id / approver_ability.
     */
    public function resolve(ApprovalStep $step, ApprovalRequest $request): void
    {
        $requester = $request->employee;

        if ($step->approver_type === ApproverType::Ability && $step->approver_ability !== null) {
            $step->approver_employee_id = null;

            return;
        }

        $approver = match ($step->approver_type) {
            ApproverType::DirectManager => $this->managerOf($requester),
            ApproverType::UnitHead => $this->unitHeadFor($requester),
            ApproverType::Employee => $step->approver_employee_id === null ? null : Employee::query()->find($step->approver_employee_id),
            ApproverType::Ability => null,
        };

        $this->assign($step, $this->acceptable($approver, $requester) ? $approver : null);
    }

    /**
     * Who an escalation goes to: the current approver's own manager, else
     * the fallback ability.
     */
    public function escalate(ApprovalStep $step, ApprovalRequest $request): void
    {
        $current = $step->approver_employee_id === null ? null : Employee::query()->find($step->approver_employee_id);
        $next = $current === null ? null : $this->managerOf($current);

        $this->assign($step, $this->acceptable($next, $request->employee) ? $next : null);
    }

    public static function fallbackAbility(): string
    {
        return (string) config('hr.approvals.fallback_ability');
    }

    private function assign(ApprovalStep $step, ?Employee $approver): void
    {
        $step->approver_employee_id = $approver?->id;
        $step->approver_ability = $approver === null ? self::fallbackAbility() : null;
    }

    private function acceptable(?Employee $approver, Employee $requester): bool
    {
        return $approver !== null
            && $approver->id !== $requester->id
            && $approver->isEmployedOn(CarbonImmutable::today());
    }

    private function managerOf(Employee $employee): ?Employee
    {
        $managerId = $employee->currentAssignment()?->manager_id;

        return $managerId === null ? null : Employee::query()->find($managerId);
    }

    /**
     * The head of the employee's unit; for a unit's own head, the head of
     * the nearest unit above with someone else at its head.
     */
    private function unitHeadFor(Employee $employee): ?Employee
    {
        $unit = $employee->currentAssignment()?->orgUnit;
        $visited = [];

        while ($unit !== null && ! in_array($unit->id, $visited, true)) {
            $visited[] = $unit->id;

            if ($unit->head_employee_id !== null && (int) $unit->head_employee_id !== (int) $employee->id) {
                return Employee::query()->find($unit->head_employee_id);
            }

            $unit = $unit->parent_id === null ? null : OrgUnit::query()->find($unit->parent_id);
        }

        return null;
    }
}
