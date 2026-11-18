<?php

namespace Add\Hr\Time\Http\Resources;

use Add\Hr\Time\Models\AttendancePolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AttendancePolicy
 */
class AttendancePolicyResource extends JsonResource
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
            'grace_minutes' => $this->grace_minutes,
            'early_leave_grace_minutes' => $this->early_leave_grace_minutes,
            'min_overtime_minutes' => $this->min_overtime_minutes,
            'overtime_requires_pre_approval' => $this->overtime_requires_pre_approval,
            'entry_tolerance_minutes' => $this->entry_tolerance_minutes,
        ];
    }
}
