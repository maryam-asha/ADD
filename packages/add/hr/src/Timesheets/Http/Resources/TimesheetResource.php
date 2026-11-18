<?php

namespace Add\Hr\Timesheets\Http\Resources;

use Add\Hr\Timesheets\Models\Timesheet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pass `reconciliation` with additional() for a single week.
 *
 * @mixin Timesheet
 */
class TimesheetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => $this->whenLoaded('employee', fn () => ['id' => $this->employee->id, 'employee_number' => $this->employee->employee_number, 'name' => $this->employee->name]),
            'week_start' => $this->week_start->toDateString(),
            'week_end' => $this->weekEnd()->toDateString(),
            'status' => $this->status->value,
            'total_hours' => $this->total_hours,
            'approval_request_id' => $this->approval_request_id,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'entries' => $this->whenLoaded('entries', fn () => $this->entries->map(fn ($entry) => [
                'id' => $entry->id,
                'cost_center_id' => $entry->cost_center_id,
                'work_date' => $entry->work_date->toDateString(),
                'hours' => $entry->hours,
                'note' => $entry->note,
            ])->values()),
        ];
    }
}
