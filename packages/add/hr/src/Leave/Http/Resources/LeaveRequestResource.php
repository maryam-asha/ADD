<?php

namespace Add\Hr\Leave\Http\Resources;

use Add\Hr\Leave\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Eager-load `leaveType`, `employee` and `approval` for lists.
 *
 * @mixin LeaveRequest
 */
class LeaveRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => [
                'id' => $this->employee->id,
                'employee_number' => $this->employee->employee_number,
                'name' => $this->employee->name,
            ],
            'leave_type' => [
                'id' => $this->leaveType->id,
                'code' => $this->leaveType->code,
                'name' => $this->leaveType->name,
                'unit' => $this->leaveType->unit->value,
            ],
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'half_day' => $this->half_day?->value,
            'hours' => $this->hours,
            'amount' => $this->amount,
            'reason' => $this->reason,
            'document_id' => $this->document_id,
            'status' => $this->status->value,
            'approval' => $this->approval === null ? null : [
                'id' => $this->approval->id,
                'status' => $this->approval->status->value,
                'current_step' => $this->approval->current_step,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
        ];
    }
}
