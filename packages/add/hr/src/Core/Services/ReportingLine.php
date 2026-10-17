<?php

namespace Add\Hr\Core\Services;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\HrException;
use Carbon\CarbonInterface;

/**
 * Who reports to whom. A manager must be employed on the day the line
 * starts, and the line may never loop back to the employee.
 */
class ReportingLine
{
    public function assertValidManager(?Employee $employee, ?int $managerId, CarbonInterface $on): void
    {
        if ($managerId === null) {
            return;
        }

        if ($employee !== null && $managerId === (int) $employee->id) {
            HrException::fail('hr::core.manager_cycle');
        }

        $manager = Employee::query()->find($managerId);

        if ($manager === null || ! $manager->isEmployedOn($on)) {
            HrException::fail('hr::core.manager_not_employed');
        }

        if ($employee === null) {
            return;
        }

        // Walk up from the proposed manager; reaching the employee means
        // they would end up managing their own manager.
        $visited = [];
        $current = $manager;

        while ($current !== null && ! in_array($current->id, $visited, true)) {
            $visited[] = $current->id;
            $next = $current->currentAssignment($on)?->manager_id;
            $next = $next === null ? null : (int) $next;

            if ($next === (int) $employee->id) {
                HrException::fail('hr::core.manager_cycle');
            }

            $current = $next === null ? null : Employee::query()->find($next);
        }
    }
}
