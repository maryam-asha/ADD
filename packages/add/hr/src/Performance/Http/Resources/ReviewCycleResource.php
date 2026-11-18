<?php

namespace Add\Hr\Performance\Http\Resources;

use Add\Hr\Performance\Models\ReviewCycle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReviewCycle
 */
class ReviewCycleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'review_template_id' => $this->review_template_id,
            'template' => $this->template,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'org_unit_id' => $this->org_unit_id,
            'status' => $this->status->value,
            'goals_due_on' => $this->goals_due_on?->toDateString(),
            'self_due_on' => $this->self_due_on?->toDateString(),
            'manager_due_on' => $this->manager_due_on?->toDateString(),
            'launched_at' => $this->launched_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'reviews_by_status' => $this->when($this->relationLoaded('reviews'), fn () => $this->reviews->countBy(fn ($review) => $review->status->value)),
        ];
    }
}
