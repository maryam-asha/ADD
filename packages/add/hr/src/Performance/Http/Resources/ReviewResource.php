<?php

namespace Add\Hr\Performance\Http\Resources;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Performance\Enums\ReviewStatus;
use Add\Hr\Performance\Models\Review;
use Add\Hr\Performance\Models\ReviewAnswer;
use Add\Hr\Performance\Services\ReviewService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A review as one of three readers sees it. HR sees everything. The
 * employee sees the reviewer's ratings and the result only once the cycle
 * closed, and never the recommendation or the calibration. The reviewer
 * sees the self review only once it was submitted, and never the
 * calibration. Items (per competency and goal) are included when the
 * answers are loaded.
 *
 * @mixin Review
 */
class ReviewResource extends JsonResource
{
    private string $audience = 'admin';

    public static function for(string $audience, Review $review): self
    {
        $resource = new self($review);
        $resource->audience = $audience;

        return $resource;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $admin = $this->audience === 'admin';
        $showManager = $this->audience !== 'employee' || $this->status === ReviewStatus::Finalized;
        $showSelf = $this->audience !== 'reviewer' || $this->status !== ReviewStatus::Open;

        return [
            'id' => $this->id,
            'cycle' => [
                'id' => $this->cycle->id,
                'name' => $this->cycle->name,
                'status' => $this->cycle->status->value,
                'period_start' => $this->cycle->period_start->toDateString(),
                'period_end' => $this->cycle->period_end->toDateString(),
                'scale_max' => $this->cycle->scaleMax(),
            ],
            'employee' => $this->person($this->employee),
            'reviewer' => $this->person($this->reviewer),
            'status' => $this->status->value,
            'items' => $this->when($this->relationLoaded('answers'), fn () => $this->items($showSelf, $showManager)),
            'self_comment' => $showSelf ? $this->self_comment : null,
            'self_submitted_at' => $this->self_submitted_at?->toIso8601String(),
            'manager_comment' => $showManager ? $this->manager_comment : null,
            'manager_submitted_at' => $this->manager_submitted_at?->toIso8601String(),
            'recommendation' => $this->when($this->audience !== 'employee', fn () => $this->recommendation?->value),
            'scores' => $showManager ? [
                'competencies' => $this->competency_score,
                'goals' => $this->goal_score,
                'overall' => $this->overall_score,
            ] : null,
            'calibrated_score' => $this->when($admin, fn () => $this->calibrated_score),
            'calibration_note' => $this->when($admin, fn () => $this->calibration_note),
            'final_rating' => $showManager ? $this->final_rating : null,
            'finalized_at' => $this->finalized_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(bool $showSelf, bool $showManager): array
    {
        $answers = $this->answers->keyBy(fn (ReviewAnswer $answer) => $answer->kind->value.':'.$answer->item_key);
        $items = [];

        foreach (app(ReviewService::class)->items($this->resource) as $item => $name) {
            [$kind, $key] = explode(':', $item, 2);
            $answer = $answers->get($item);
            $items[] = [
                'kind' => $kind,
                'key' => $key,
                'name' => $name,
                'self_rating' => $showSelf ? $answer?->self_rating : null,
                'self_comment' => $showSelf ? $answer?->self_comment : null,
                'manager_rating' => $showManager ? $answer?->manager_rating : null,
                'manager_comment' => $showManager ? $answer?->manager_comment : null,
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function person(?Employee $employee): ?array
    {
        return $employee === null ? null : [
            'id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'name' => $employee->name,
        ];
    }
}
