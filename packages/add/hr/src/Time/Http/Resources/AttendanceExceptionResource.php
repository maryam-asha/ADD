<?php

namespace Add\Hr\Time\Http\Resources;

use Add\Hr\Time\Models\AttendanceException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AttendanceException
 */
class AttendanceExceptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attendance_day_id' => $this->attendance_day_id,
            'employee' => $this->whenLoaded('employee', fn () => ['id' => $this->employee->id, 'name' => $this->employee->name]),
            'work_date' => $this->work_date->toDateString(),
            'type' => $this->type->value,
            'status' => $this->status->value,
            'resolution' => $this->resolution?->value,
            'note' => $this->note,
            'resolved_by' => $this->resolved_by,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
        ];
    }
}
