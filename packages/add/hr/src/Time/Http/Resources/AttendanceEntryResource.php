<?php

namespace Add\Hr\Time\Http\Resources;

use Add\Hr\Time\Models\AttendanceEntry;
use Add\Hr\Time\Support\LocalTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AttendanceEntry
 */
class AttendanceEntryResource extends JsonResource
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
            'check_in_at' => LocalTime::iso($this->check_in_at),
            'check_out_at' => LocalTime::iso($this->check_out_at),
            'note' => $this->note,
            'status' => $this->status->value,
            'approval_request_id' => $this->approval_request_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
