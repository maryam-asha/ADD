<?php

namespace App\Http\Resources;

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

        $baseCurrency = $this->pricing_currency ?? 'USD';
        $targetCurrency = app(\App\Domain\Finance\Services\CurrencyResolver::class)->resolve($request, $user);
        $rateUsd = $this->hourly_rate !== null ? (float) $this->hourly_rate : 0.0;
        $rateDisplay = number_format($rateUsd, 2, '.', '');

        if ($rateUsd > 0 && $targetCurrency !== $baseCurrency) {
            $converted = app(\App\Domain\Finance\Services\CurrencyConversionService::class)->convert(
                $rateUsd,
                $baseCurrency,
                $targetCurrency
            );
            if ($converted !== null) {
                $rateDisplay = number_format($converted, 2, '.', '');
            }
        }

        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'zone_id' => $this->zone_id,
            'space_type' => $this->space_type,
            'allocation_model' => $this->allocation_model,
            'is_lockable' => $this->is_lockable,
            'capacity' => $this->capacity,
            'image_url' => $this->image_url,
            'main_image' => $this->main_image,
            'images' => $this->images,
            'hourly_rate' => $this->hourly_rate,
            'pricing_currency' => $this->pricing_currency,
            'hourly_rate_usd' => $rateUsd,
            'hourly_rate_display' => $rateDisplay,
            'currency' => $targetCurrency,
            'status' => $this->status,
            'status_reason' => $this->status_reason,
            'status_from' => $this->status_from,
            'status_until' => $this->status_until,
        ];
    }
}
