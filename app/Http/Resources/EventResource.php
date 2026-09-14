<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isAdmin = $request->is('api/v1/admin/*');
        $header = strtolower((string) ($request->header('lang') ?: $request->header('Accept-Language')));
        $locale = str_contains($header, 'en') ? 'en' : (str_contains($header, 'ar') ? 'ar' : app()->getLocale());

        $user = null;
        try {
            $user = $request->user('sanctum');
        } catch (\Throwable) {
        }

        $baseCurrency = $this->currency ?? 'USD';
        $targetCurrency = app(\App\Domain\Finance\Services\CurrencyResolver::class)->resolve($request, $user);
        $priceUsd = $this->price !== null ? (float) $this->price : 0.0;
        $priceDisplay = number_format($priceUsd, 2, '.', '');

        if (! $this->is_free && $priceUsd > 0 && $targetCurrency !== $baseCurrency) {
            $converted = app(\App\Domain\Finance\Services\CurrencyConversionService::class)->convert(
                $priceUsd,
                $baseCurrency,
                $targetCurrency
            );
            if ($converted !== null) {
                $priceDisplay = number_format($converted, 2, '.', '');
            }
        }

        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'space_id' => $this->space_id,
            'category_id' => $this->category_id,
            'title' => $isAdmin ? $this->title : $this->translate('title', $locale),
            'description' => $isAdmin ? $this->description : $this->translate('description', $locale),
            'category' => $this->category ? ($isAdmin ? new EventCategoryResource($this->category) : [
                'id' => $this->category->id,
                'name' => $this->category->translate('name', $locale),
            ]) : null,
            'start_at' => $isAdmin ? $this->start_at?->toISOString() : $this->start_at?->format('Y-m-d'),
            'end_at' => $isAdmin ? $this->end_at?->toISOString() : $this->end_at?->format('Y-m-d'),
            'location' => $this->location,
            'capacity' => $this->capacity,
            'is_free' => (bool) ($this->is_free ?? true),
            'price' => $priceUsd,
            'currency' => $baseCurrency,
            'price_usd' => $priceUsd,
            'price_display' => $priceDisplay,
            'display_currency' => $targetCurrency,
            'image_url' => $this->image_url,
            'status' => $this->status,
            'order' => $this->order,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
