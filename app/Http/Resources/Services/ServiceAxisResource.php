<?php

namespace App\Http\Resources\Services;

use App\Domain\Services\Models\ServiceAxis;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ServiceAxis */
class ServiceAxisResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->translate('name'),
            'color' => $this->color,
            'service_types' => ServiceTypeResource::collection($this->whenLoaded('serviceTypes')),
        ];
    }
}
