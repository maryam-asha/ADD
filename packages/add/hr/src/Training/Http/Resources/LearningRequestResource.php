<?php

namespace Add\Hr\Training\Http\Resources;

use Add\Hr\Training\Models\LearningRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LearningRequest
 */
class LearningRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'course_id' => $this->course_id,
            'title' => $this->title,
            'provider' => $this->provider,
            'starts_on' => $this->starts_on?->toDateString(),
            'year' => $this->year,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'justification' => $this->justification,
            'status' => $this->status->value,
            'approval_request_id' => $this->approval_request_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
