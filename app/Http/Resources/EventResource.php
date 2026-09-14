<?php

namespace App\Http\Resources;

use App\Domain\Finance\Services\CurrencyResolver;
use App\Domain\Finance\Services\PointConversionService;
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

        // Points are the stored price; every currency figure below is
        // derived at read time (docs/superpowers/specs/
        // 2026-09-12-add-point-system-design.md, Invariant 1). This is the
        // same shape PlanResource and SpaceResource emit.
        $points = app(PointConversionService::class);
        $targetCurrency = app(CurrencyResolver::class)->resolve($request, $user);

        $pricePoints = (int) ($this->price_points ?? 0);
        $priceUsd = $points->pointsToUsd($pricePoints);
        $priceDisplay = $points->pointsToCurrency($pricePoints, $targetCurrency) ?? $priceUsd;

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
            'price_points' => $pricePoints,
            'price_usd' => $priceUsd,
            'price_display' => $priceDisplay,
            'currency' => $targetCurrency,
            'display_currency' => $targetCurrency,
            'image_url' => $this->image_url,
            'status' => $this->status,
            'order' => $this->order,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
