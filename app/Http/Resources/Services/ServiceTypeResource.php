<?php

namespace App\Http\Resources\Services;

use App\Domain\Services\Models\ServiceType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ServiceType */
class ServiceTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->translate('name'),
            'description' => $this->translate('description'),
            'icon' => $this->icon,
            'is_active' => $this->is_active,
            'is_coming_soon' => $this->is_coming_soon,
            'requires_location' => $this->requires_location,
            'requires_review' => $this->requires_review,
            'request_method' => $this->request_method->value,
            'request_config' => $this->request_config,
            // Fields only needed when viewing a single service type (for form rendering)
            'fields' => ServiceTypeFieldResource::collection($this->whenLoaded('activeFields')),
        ];
    }
}
