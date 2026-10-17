<?php

namespace Add\Hr\Recruitment\Http\Resources;

use Add\Hr\Recruitment\Models\JobOpening;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JobOpening
 */
class JobOpeningResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'requisition_id' => $this->requisition_id,
            'title' => $this->title,
            'description' => $this->description,
            'employment_type' => $this->employment_type->value,
            'work_location' => $this->whenLoaded('workLocation', fn () => $this->workLocation === null ? null : ['id' => $this->workLocation->id, 'name' => $this->workLocation->name]),
            'visibility' => $this->visibility->value,
            'status' => $this->status->value,
            'closes_on' => $this->closes_on?->toDateString(),
            'opened_at' => $this->opened_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'applications_count' => $this->whenCounted('applications'),
        ];
    }
}
