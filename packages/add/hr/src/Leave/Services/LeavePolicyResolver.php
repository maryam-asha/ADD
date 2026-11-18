<?php

namespace Add\Hr\Leave\Services;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Leave\Models\LeavePolicy;
use Add\Hr\Leave\Models\LeaveType;
use Carbon\CarbonInterface;

/**
 * The policy that governs an employee's leave of a type on a day: among
 * the policies in force that apply to them (contract type, grade, unit),
 * the most specific — a grade beats a unit beats a contract type beats
 * everyone — then the most recent.
 */
class LeavePolicyResolver
{
    public function for(Employee $employee, LeaveType $type, CarbonInterface $on): ?LeavePolicy
    {
        $assignment = $employee->currentAssignment($on);
        $contract = $employee->currentContract($on);

        return LeavePolicy::query()
            ->where('leave_type_id', $type->id)
            ->activeOn($on)
            ->get()
            ->filter(fn (LeavePolicy $policy) => $this->matches($policy->job_grade_ids, $assignment?->job_grade_id)
                && $this->matches($policy->org_unit_ids, $assignment?->org_unit_id)
                && $this->matches($policy->contract_types, $contract?->type->value))
            ->sort(fn (LeavePolicy $a, LeavePolicy $b) => [$this->specificity($b), $b->effective_from->toDateString(), $b->id]
                <=> [$this->specificity($a), $a->effective_from->toDateString(), $a->id])
            ->first();
    }

    /**
     * @param  list<int|string>|null  $allowed
     */
    private function matches(?array $allowed, int|string|null $value): bool
    {
        return $allowed === null || ($value !== null && in_array((string) $value, array_map('strval', $allowed), true));
    }

    private function specificity(LeavePolicy $policy): int
    {
        return ($policy->job_grade_ids !== null ? 4 : 0)
            + ($policy->org_unit_ids !== null ? 2 : 0)
            + ($policy->contract_types !== null ? 1 : 0);
    }
}
