<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventCategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isAdmin = $request->is('api/v1/admin/*');
        $header = strtolower((string) ($request->header('lang') ?: $request->header('Accept-Language')));
        $locale = str_contains($header, 'en') ? 'en' : (str_contains($header, 'ar') ? 'ar' : app()->getLocale());

        return [
            'id' => $this->id,
            'name' => $isAdmin ? $this->name : $this->translate('name', $locale),
            'slug' => $this->slug,
            'description' => $isAdmin ? $this->description : $this->translate('description', $locale),
            'color' => $this->color,
            'icon' => $this->icon,
            'order' => $this->order,
            'is_active' => (bool) $this->is_active,
            'events_count' => $this->whenCounted('events'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
