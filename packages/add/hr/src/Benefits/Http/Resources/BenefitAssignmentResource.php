<?php

namespace Add\Hr\Benefits\Http\Resources;

use Add\Hr\Benefits\Models\BenefitAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BenefitAssignment
 */
class BenefitAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'benefit_id' => $this->benefit_id,
            'benefit' => new BenefitResource($this->whenLoaded('benefit')),
            'target' => $this->target(),
            'employee' => $this->whenLoaded('employee', fn () => $this->employee === null ? null : [
                'id' => $this->employee->id,
                'employee_number' => $this->employee->employee_number,
                'name' => $this->employee->name,
            ]),
            'job_grade' => $this->whenLoaded('jobGrade', fn () => $this->jobGrade === null ? null : ['id' => $this->jobGrade->id, 'name' => $this->jobGrade->name]),
            'org_unit' => $this->whenLoaded('orgUnit', fn () => $this->orgUnit === null ? null : ['id' => $this->orgUnit->id, 'name' => $this->orgUnit->name]),
            'employee_id' => $this->employee_id,
            'job_grade_id' => $this->job_grade_id,
            'org_unit_id' => $this->org_unit_id,
            'amount' => $this->amount,
            'employer_cost' => $this->employer_cost,
            'excluded' => $this->excluded,
            'effective_from' => $this->effective_from->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
        ];
    }
}
