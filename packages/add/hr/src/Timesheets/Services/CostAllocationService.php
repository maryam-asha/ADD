<?php

namespace Add\Hr\Timesheets\Services;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\HrException;
use Add\Hr\Timesheets\Models\CostAllocation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * An employee's planned cost split, effective dated like the rest of the
 * employment: a new split closes the one running the day before it starts,
 * so the rows are the history. A split sums to exactly 100.
 */
class CostAllocationService
{
    /**
     * @param  list<array{cost_center_id: int, percent: float|int|string}>  $lines
     */
    public function replace(Employee $employee, CarbonImmutable $from, array $lines, int|string|null $actorId): Collection
    {
        if (abs(array_sum(array_map(fn (array $line) => (float) $line['percent'], $lines)) - 100) > 0.001) {
            HrException::fail('hr::timesheets.allocation_not_100');
        }

        return DB::transaction(function () use ($employee, $from, $lines, $actorId) {
            Employee::query()->lockForUpdate()->findOrFail($employee->id);

            if (CostAllocation::query()->where('employee_id', $employee->id)->where('effective_from', '>=', $from->toDateString())->exists()) {
                HrException::fail('hr::timesheets.allocation_date_invalid');
            }

            CostAllocation::query()
                ->where('employee_id', $employee->id)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from->toDateString()))
                ->update(['effective_to' => $from->subDay()->toDateString()]);

            $created = collect($lines)->map(fn (array $line) => CostAllocation::query()->create([
                'employee_id' => $employee->id,
                'cost_center_id' => $line['cost_center_id'],
                'percent' => $line['percent'],
                'effective_from' => $from,
                'created_by' => $actorId,
            ]));

            HrAudit::record('cost_allocation_changed', $employee, ['from' => $from->toDateString(), 'lines' => $lines]);

            return $created;
        });
    }
}
