<?php

namespace App\Http\Resources;

use App\Domain\Finance\Services\CurrencyConversionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'is_subscription' => $this->is_subscription,
            'price' => $this->price,
            'pricing_currency' => $this->pricing_currency,
            'duration_days' => $this->duration_days,
            'included_hours' => $this->included_hours,
            'overage_rate' => $this->overage_rate,
            'is_active' => $this->is_active,
            'order' => $this->order,
            'created_at' => $this->created_at,
        ];

        // Resolved directly from the guard, not the route — this lets
        // conversion opportunistically activate even on public routes with
        // no auth:sanctum middleware, as long as a valid bearer token is
        // sent (Unit 1 design, 2026-08-09).
        $user = $request->user('sanctum');

        if ($user?->preferred_currency && $user->preferred_currency !== $this->pricing_currency) {
            $converted = app(CurrencyConversionService::class)->convert(
                (float) $this->price,
                $this->pricing_currency,
                $user->preferred_currency
            );

            if ($converted !== null) {
                $data['converted_amount'] = $converted;
                $data['converted_currency'] = $user->preferred_currency;
            }
        }

        return $data;
    }
}
