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
        $locale = app()->getLocale();

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->translate('name', $locale),
            'description' => $this->translate('description', $locale),
            'icon' => $this->icon,
            'is_active' => (bool) $this->is_active,
            'is_coming_soon' => (bool) $this->is_coming_soon,
            'status' => $this->computedStatus(),
            'can_request' => $this->is_active && ! $this->is_coming_soon,
            'request_method' => $this->request_method->value,
            'axis' => $this->whenLoaded('axis', fn () => [
                'id' => $this->axis->id,
                'slug' => $this->axis->slug,
                'name' => $this->axis->translate('name', $locale),
                'color' => $this->axis->color,
            ]),
            // Form fields: only included when viewing single service details (activeFields loaded)
            'registration_form' => ServiceTypeFieldResource::collection($this->whenLoaded('activeFields')),
            'fields' => ServiceTypeFieldResource::collection($this->whenLoaded('activeFields')),
        ];
    }

    private function computedStatus(): string
    {
        if (! $this->is_active) {
            return 'unavailable';
        }

        if ($this->is_coming_soon) {
            return 'coming_soon';
        }

        return 'open';
    }
}
