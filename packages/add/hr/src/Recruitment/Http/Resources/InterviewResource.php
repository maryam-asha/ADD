<?php

namespace Add\Hr\Recruitment\Http\Resources;

use Add\Hr\Recruitment\Models\Interview;
use Add\Hr\Time\Support\LocalTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Interview
 */
class InterviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_id' => $this->application_id,
            'scheduled_at' => LocalTime::iso($this->scheduled_at),
            'duration_minutes' => $this->duration_minutes,
            'mode' => $this->mode->value,
            'location' => $this->location,
            'notes' => $this->notes,
            'status' => $this->status->value,
            'interviewers' => $this->whenLoaded('interviewers', fn () => $this->interviewers->map(fn ($employee) => [
                'id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'name' => $employee->name,
            ])),
            'scorecards' => $this->whenLoaded('scorecards', fn () => $this->scorecards->map(fn ($card) => [
                'employee_id' => $card->employee_id,
                'recommendation' => $card->recommendation->value,
                'ratings' => $card->ratings === null ? null : (object) $card->ratings,
                'notes' => $card->notes,
                'submitted_at' => $card->submitted_at?->toIso8601String(),
            ])),
        ];
    }
}
