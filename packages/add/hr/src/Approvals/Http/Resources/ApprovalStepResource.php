<?php

namespace Add\Hr\Approvals\Http\Resources;

use Add\Hr\Approvals\Models\ApprovalStep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ApprovalStep
 */
class ApprovalStepResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'sort' => $this->sort,
            'approver_type' => $this->approver_type->value,
            'approver_employee' => $this->approverEmployee
                ? ['id' => $this->approverEmployee->id, 'name' => $this->approverEmployee->name]
                : null,
            'approver_ability' => $this->approver_ability,
            'status' => $this->status->value,
            'decided_via' => $this->decided_via?->value,
            'decided_by' => $this->decided_by,
            'comment' => $this->comment,
            'due_at' => $this->due_at?->toIso8601String(),
            'escalated_at' => $this->escalated_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
        ];
    }
}
