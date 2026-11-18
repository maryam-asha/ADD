<?php

namespace Add\Hr\Approvals\Http\Resources;

use Add\Hr\Approvals\Models\ApprovalRequest;
use Add\Hr\Approvals\Services\ApprovalTypes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A request with its subject as its type's handler describes it. Eager-load
 * `employee`, `subject` and `steps.approverEmployee` for lists.
 *
 * @mixin ApprovalRequest
 */
class ApprovalRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_type' => $this->request_type,
            'status' => $this->status->value,
            'employee' => [
                'id' => $this->employee->id,
                'employee_number' => $this->employee->employee_number,
                'name' => $this->employee->name,
            ],
            'submitted_by' => $this->submitted_by,
            'current_step' => $this->current_step,
            'subject' => $this->subject === null
                ? null
                : app(ApprovalTypes::class)->handler($this->request_type)->summary($this->subject),
            'steps' => ApprovalStepResource::collection($this->whenLoaded('steps')),
            'created_at' => $this->created_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
        ];
    }
}
