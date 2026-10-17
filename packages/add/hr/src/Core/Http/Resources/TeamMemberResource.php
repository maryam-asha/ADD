<?php

namespace Add\Hr\Core\Http\Resources;

use Add\Hr\Core\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A direct report as their manager sees them: who and what, nothing personal.
 *
 * @mixin Employee
 */
class TeamMemberResource extends JsonResource
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
            'job_title' => $assignment?->resolvedTitle(),
            'org_unit' => $assignment?->orgUnit ? ['id' => $assignment->orgUnit->id, 'name' => $assignment->orgUnit->name] : null,
        ];
    }
}
