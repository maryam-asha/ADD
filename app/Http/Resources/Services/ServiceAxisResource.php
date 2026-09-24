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
        $locale = app()->getLocale();

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->translate('name', $locale),
            'color' => $this->color,
            'services_count' => $this->when(
                isset($this->service_types_count),
                fn () => (int) $this->service_types_count
            ),
            'service_types' => ServiceTypeResource::collection($this->whenLoaded('serviceTypes')),
        ];
    }
}
