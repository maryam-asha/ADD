<?php

namespace Add\Hr\Time\Http\Resources;

use Add\Hr\Time\Models\OvertimeRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OvertimeRequest
 */
class OvertimeRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => $this->whenLoaded('employee', fn () => ['id' => $this->employee->id, 'name' => $this->employee->name]),
            'work_date' => $this->work_date->toDateString(),
            'minutes' => $this->minutes,
            'kind' => $this->kind->value,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'approval_request_id' => $this->approval_request_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
