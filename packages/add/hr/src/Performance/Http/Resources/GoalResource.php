<?php

namespace Add\Hr\Performance\Http\Resources;

use Add\Hr\Performance\Models\Goal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Goal
 */
class GoalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'review_cycle_id' => $this->review_cycle_id,
            'title' => $this->title,
            'description' => $this->description,
            'metric' => $this->metric,
            'target' => $this->target,
            'weight' => $this->weight,
            'progress' => $this->progress,
            'status' => $this->status->value,
            'due_on' => $this->due_on?->toDateString(),
        ];
    }
}
