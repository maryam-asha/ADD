<?php

namespace App\Http\Resources;

use App\Domain\Finance\Services\CurrencyResolver;
use App\Domain\Finance\Services\PointConversionService;
use App\Support\PlainAmount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = null;
        try {
            $user = $request->user('sanctum');
        } catch (\Throwable) {
        }

        $points = app(PointConversionService::class);
        $targetCurrency = app(CurrencyResolver::class)->resolve($request, $user);

        $rate = $this->hourly_rate_points !== null ? (int) $this->hourly_rate_points : null;

        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'zone_id' => $this->zone_id,
            'name' => $this->name,
            'space_type' => $this->space_type,
            'allocation_model' => $this->allocation_model,
            'is_lockable' => $this->is_lockable,
            'capacity' => $this->capacity,
            'image_url' => $this->image_url,
            'main_image' => $this->main_image,
            'images' => $this->images,
            'hourly_rate_points' => $rate,
            'hourly_rate_usd' => $rate !== null ? PlainAmount::of($points->pointsToUsd($rate)) : null,
            'hourly_rate_display' => $rate !== null
                ? PlainAmount::of($points->pointsToCurrency($rate, $targetCurrency) ?? $points->pointsToUsd($rate))
                : null,
            'currency' => $targetCurrency,
            // Null on a space not refreshed since creation: the column's
            // database default (true) applies.
            'allows_extension' => (bool) ($this->allows_extension ?? true),
            'cancellation_window_minutes' => $this->cancellation_window_minutes,
            'amenities' => $this->amenities->pluck('amenity'),
            'status' => $this->status,
            'status_reason' => $this->status_reason,
            'status_from' => $this->status_from,
            'status_until' => $this->status_until,
        ];
    }
}
