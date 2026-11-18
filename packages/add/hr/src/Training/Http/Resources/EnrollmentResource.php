<?php

namespace Add\Hr\Training\Http\Resources;

use Add\Hr\Training\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Enrollment
 */
class EnrollmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'session' => $this->whenLoaded('session', fn () => new TrainingSessionResource($this->session)),
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'employee_number' => $this->employee->employee_number,
                'name' => $this->employee->name,
            ]),
            'status' => $this->status->value,
            'completed_on' => $this->completed_on?->toDateString(),
            'score' => $this->score,
        ];
    }
}
