<?php

namespace App\Http\Resources;

use App\Domain\Finance\Services\CurrencyConversionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
        // sent (Unit 1 design, 2026-08-09). Resolving the guard fires
        // Sanctum's TokenAuthenticated event, which
        // EnsureAuthenticatedUserIsActive listens to and aborts (403) for a
        // suspended/blocked account — never intended to affect this
        // opportunistic, otherwise-always-200 public listing, so that abort
        // is treated the same as "no user resolvable for conversion".
        try {
            $user = $request->user('sanctum');
        } catch (HttpException) {
            $user = null;
        }

        if ($user?->preferred_currency && $user->preferred_currency !== $this->pricing_currency) {
            $converted = app(CurrencyConversionService::class)->convert(
                (float) $this->price,
                $this->pricing_currency,
                $user->preferred_currency
            );

            if ($converted !== null) {
                $data['converted_amount'] = number_format($converted, 2, '.', '');
                $data['converted_currency'] = $user->preferred_currency;
            }
        }

        return $data;
    }
}
