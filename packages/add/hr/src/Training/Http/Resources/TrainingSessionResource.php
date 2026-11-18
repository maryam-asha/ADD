<?php

namespace Add\Hr\Training\Http\Resources;

use Add\Hr\Training\Models\TrainingSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TrainingSession
 */
class TrainingSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course' => $this->whenLoaded('course', fn () => [
                'id' => $this->course->id,
                'code' => $this->course->code,
                'title' => $this->course->title,
                'delivery' => $this->course->delivery->value,
            ]),
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'location' => $this->location,
            'trainer' => $this->trainer,
            'capacity' => $this->capacity,
            'seats_taken' => $this->whenHas('seats_taken'),
            'self_enrollment' => $this->self_enrollment,
            'status' => $this->status->value,
        ];
    }
}
