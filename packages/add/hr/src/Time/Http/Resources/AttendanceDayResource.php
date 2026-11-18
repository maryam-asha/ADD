<?php

namespace Add\Hr\Time\Http\Resources;

use Add\Hr\Time\Models\AttendanceDay;
use Add\Hr\Time\Support\LocalTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AttendanceDay
 */
class AttendanceDayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => $this->whenLoaded('employee', fn () => ['id' => $this->employee->id, 'employee_number' => $this->employee->employee_number, 'name' => $this->employee->name]),
            'work_date' => $this->work_date->toDateString(),
            'status' => $this->status->value,
            'shift' => $this->whenLoaded('shiftTemplate', fn () => $this->shiftTemplate ? ['id' => $this->shiftTemplate->id, 'name' => $this->shiftTemplate->name, 'starts_at' => $this->shiftTemplate->starts_at, 'ends_at' => $this->shiftTemplate->ends_at] : null),
            'scheduled_start' => LocalTime::iso($this->scheduled_start),
            'scheduled_end' => LocalTime::iso($this->scheduled_end),
            'first_in' => LocalTime::iso($this->first_in),
            'last_out' => LocalTime::iso($this->last_out),
            'punch_count' => $this->punch_count,
            'worked_minutes' => $this->worked_minutes,
            'late_minutes' => $this->late_minutes,
            'early_leave_minutes' => $this->early_leave_minutes,
            'overtime_minutes' => $this->overtime_minutes,
            'exceptions' => AttendanceExceptionResource::collection($this->whenLoaded('exceptions')),
        ];
    }
}
