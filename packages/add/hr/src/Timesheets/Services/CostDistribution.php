<?php

namespace Add\Hr\Timesheets\Services;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Timesheets\Enums\TimesheetStatus;
use Add\Hr\Timesheets\Models\CostAllocation;
use Add\Hr\Timesheets\Models\TimesheetEntry;
use Carbon\CarbonImmutable;

/**
 * How an employee's cost over a range splits across cost centers — what
 * payroll posts and what the donor reports show:
 *
 * 1. approved timesheet hours in the range, when there are any;
 * 2. else the planned split (cost allocations), weighted by the days each
 *    split was in force;
 * 3. else nothing: the cost is unallocated (an empty result).
 *
 * Shares are fractions summing to 1.
 */
class CostDistribution
{
    /**
     * @return array<int, float> cost_center_id => share
     */
    public function sharesFor(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $hours = $this->approvedHours($from, $to, $employee->id)[$employee->id] ?? [];

        if (array_sum($hours) > 0) {
            return $this->normalise($hours);
        }

        $weights = [];

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            if (! $employee->isEmployedOn($day)) {
                continue;
            }

            foreach (CostAllocation::query()->where('employee_id', $employee->id)->activeOn($day)->get() as $line) {
                $weights[$line->cost_center_id] = ($weights[$line->cost_center_id] ?? 0) + (float) $line->percent;
            }
        }

        return $this->normalise($weights);
    }

    /**
     * Approved hours per employee per cost center.
     *
     * @return array<int, array<int, float>> employee_id => [cost_center_id => hours]
     */
    public function approvedHours(CarbonImmutable $from, CarbonImmutable $to, ?int $employeeId = null): array
    {
        $rows = TimesheetEntry::query()
            ->join('hr_timesheets', 'hr_timesheets.id', '=', 'hr_timesheet_entries.timesheet_id')
            ->where('hr_timesheets.status', TimesheetStatus::Approved->value)
            ->whereBetween('hr_timesheet_entries.work_date', [$from->toDateString(), $to->toDateString()])
            ->when($employeeId !== null, fn ($q) => $q->where('hr_timesheets.employee_id', $employeeId))
            ->groupBy('hr_timesheets.employee_id', 'hr_timesheet_entries.cost_center_id')
            ->selectRaw('hr_timesheets.employee_id as employee_id, hr_timesheet_entries.cost_center_id as cost_center_id, SUM(hr_timesheet_entries.hours) as hours')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row->employee_id][(int) $row->cost_center_id] = round((float) $row->hours, 2);
        }

        return $result;
    }

    /**
     * @param  array<int, float>  $weights
     * @return array<int, float>
     */
    private function normalise(array $weights): array
    {
        $total = array_sum($weights);

        if ($total <= 0) {
            return [];
        }

        ksort($weights);

        return array_map(fn (float $weight) => round($weight / $total, 6), $weights);
    }
}
