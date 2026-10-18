<?php

namespace Add\Hr\Recruitment\Http\Resources;

use Add\Hr\Recruitment\Models\JobOpening;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JobOpening
 */
class PublicJobOpeningResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'employment_type' => $this->employment_type->value,
            'location' => $this->whenLoaded('workLocation', fn () => $this->workLocation?->name),
            'closes_on' => $this->closes_on?->toDateString(),
        ];
    }
}
