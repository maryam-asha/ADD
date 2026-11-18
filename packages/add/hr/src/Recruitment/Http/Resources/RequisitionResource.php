<?php

namespace Add\Hr\Recruitment\Http\Resources;

use Add\Hr\Recruitment\Enums\RequisitionStatus;
use Add\Hr\Recruitment\Models\Requisition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Requisition
 */
class RequisitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'org_unit' => $this->whenLoaded('orgUnit', fn () => ['id' => $this->orgUnit->id, 'name' => $this->orgUnit->name]),
            'org_unit_id' => $this->org_unit_id,
            'position_id' => $this->position_id,
            'job_grade_id' => $this->job_grade_id,
            'headcount' => $this->headcount,
            'hired' => $this->when($this->status !== RequisitionStatus::Pending, fn () => $this->hiredCount()),
            'employment_type' => $this->employment_type->value,
            'reason' => $this->reason->value,
            'replaces_employee_id' => $this->replaces_employee_id,
            'hiring_manager' => $this->whenLoaded('hiringManager', fn () => $this->hiringManager === null ? null : [
                'id' => $this->hiringManager->id,
                'employee_number' => $this->hiringManager->employee_number,
                'name' => $this->hiringManager->name,
            ]),
            'budget_min' => $this->budget_min,
            'budget_max' => $this->budget_max,
            'budget_currency' => $this->budget_currency,
            'justification' => $this->justification,
            'status' => $this->status->value,
            'approval_request_id' => $this->approval_request_id,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'filled_at' => $this->filled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
