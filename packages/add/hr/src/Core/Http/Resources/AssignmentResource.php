<?php

namespace Add\Hr\Core\Http\Resources;

use Add\Hr\Core\Models\Assignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Assignment
 */
class AssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'effective_from' => $this->effective_from->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'org_unit' => $this->orgUnit ? ['id' => $this->orgUnit->id, 'name' => $this->orgUnit->name] : null,
            'position' => $this->position ? ['id' => $this->position->id, 'title' => $this->position->title] : null,
            'job_grade' => $this->jobGrade ? ['id' => $this->jobGrade->id, 'code' => $this->jobGrade->code, 'name' => $this->jobGrade->name] : null,
            'manager' => $this->manager ? [
                'id' => $this->manager->id,
                'employee_number' => $this->manager->employee_number,
                'name' => $this->manager->name,
            ] : null,
            'work_location' => $this->workLocation ? ['id' => $this->workLocation->id, 'name' => $this->workLocation->name] : null,
            'job_title' => $this->resolvedTitle(),
            'employment_type' => $this->employment_type->value,
            'work_mode' => $this->work_mode->value,
            'work_pattern_id' => $this->work_pattern_id,
        ];
    }
}
