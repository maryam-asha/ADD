<?php

namespace Add\Hr\Core\Http\Resources;

use Add\Hr\Core\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An employee in a list. Eager-load `activeAssignment.orgUnit` and
 * `activeAssignment.position` to keep lists to a few queries.
 *
 * @mixin Employee
 */
class EmployeeSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $assignment = $this->activeAssignment;

        return [
            'id' => $this->id,
            'employee_number' => $this->employee_number,
            'name' => $this->name,
            'status' => $this->status->value,
            'user_id' => $this->user_id,
            'hired_on' => $this->hired_on->toDateString(),
            'job_title' => $assignment?->resolvedTitle(),
            'org_unit' => $assignment?->orgUnit ? ['id' => $assignment->orgUnit->id, 'name' => $assignment->orgUnit->name] : null,
        ];
    }
}
