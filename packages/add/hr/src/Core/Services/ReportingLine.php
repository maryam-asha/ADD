<?php

namespace Add\Hr\Core\Services;

use Add\Hr\Core\Models\Assignment;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\HrException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Who reports to whom. A manager must be employed on the day the line
 * starts, and the line may never loop back to the employee — on that day or
 * on any later day a change is already recorded for, since the new line
 * runs on past them.
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

        foreach ($this->daysToCheck($on) as $day) {
            $this->assertNoLoop($manager, (int) $employee->id, $day);
        }
    }

    /**
     * Walk up from the proposed manager on that day; reaching the employee
     * means they would end up managing their own manager.
     */
    private function assertNoLoop(Employee $manager, int $employeeId, CarbonImmutable $day): void
    {
        $visited = [];
        $current = $manager;

        while ($current !== null && ! in_array($current->id, $visited, true)) {
            $visited[] = $current->id;
            $next = $current->currentAssignment($day)?->manager_id;
            $next = $next === null ? null : (int) $next;

            if ($next === $employeeId) {
                HrException::fail('hr::core.manager_cycle');
            }

            $current = $next === null ? null : Employee::query()->find($next);
        }
    }

    /**
     * The start day, plus every later day on which some recorded assignment
     * begins — the only days the shape of the tree can change.
     *
     * @return list<CarbonImmutable>
     */
    private function daysToCheck(CarbonInterface $on): array
    {
        $start = $on->toDateString();

        $later = Assignment::query()
            ->where('effective_from', '>', $start)
            ->distinct()
            ->pluck('effective_from')
            ->map(fn ($day) => CarbonImmutable::parse($day)->toDateString());

        return collect([$start])
            ->merge($later)
            ->unique()
            ->map(fn (string $day) => CarbonImmutable::parse($day))
            ->values()
            ->all();
    }
}
