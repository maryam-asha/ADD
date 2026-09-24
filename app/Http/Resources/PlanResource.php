<?php

namespace App\Http\Resources;

use App\Domain\Finance\Services\CurrencyConversionService;
use App\Domain\Finance\Services\CurrencyResolver;
use App\Domain\Finance\Services\PointConversionService;
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
            'plan_module_id' => $this->plan_module_id,
            'plan_module' => $this->whenLoaded('planModule', fn () => [
                'id' => $this->planModule->id,
                'name' => $this->planModule->name,
                'slug' => $this->planModule->slug,
                'icon' => $this->planModule->icon,
            ]),
            'name' => $this->name,
            'subtitle' => $this->subtitle,
            'is_subscription' => $this->is_subscription,
            'duration_days' => $this->duration_days,
            'included_hours' => $this->included_hours,
            'is_active' => $this->is_active,
            'order' => $this->order,
            'balances' => $this->whenLoaded('balances', fn () => $this->balances->map(fn ($b) => [
                'id' => $b->id,
                'category' => $b->category->value,
                'amount_points' => (int) $b->amount_points,
                'restricted_space_id' => $b->restricted_space_id,
                'description' => $b->description,
                'order' => $b->order,
            ])),
            'features' => $this->whenLoaded('features', fn () => $this->features->map(fn ($f) => [
                'id' => $f->id,
                'name' => $f->name,
                'value' => $f->value,
                'icon' => $f->icon,
                'is_included' => $f->is_included,
                'order' => $f->order,
            ])),
            'discounts' => $this->whenLoaded('discounts', fn () => $this->discounts->map(fn ($d) => [
                'id' => $d->id,
                'percentage' => (int) $d->percentage,
                'space_id' => $d->space_id,
                'space_type' => $d->space_type?->value,
                'usage_limit' => $d->usage_limit,
                'description' => $d->description,
                'is_active' => $d->is_active,
                'order' => $d->order,
            ])),
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

        // price_points is the authoritative stored value; price_usd and
        // price_display are derived per request from the point rate and the
        // resolved target currency (docs/superpowers/specs/2026-09-12-add-point-system-design.md).
        $points = app(PointConversionService::class);
        $targetCurrency = app(CurrencyResolver::class)->resolve($request, $user);
        $baseCurrency = app(CurrencyConversionService::class)->baseCurrencyCode();

        $convertedDisplay = $points->pointsToCurrency((int) $this->price_points, $targetCurrency);

        $data['price_points'] = (int) $this->price_points;
        $data['price_usd'] = $points->pointsToUsd((int) $this->price_points);
        $data['price_display'] = $convertedDisplay ?? $data['price_usd'];
        $data['currency'] = $targetCurrency;
        $data['overage_rate_points'] = $this->overage_rate_points !== null
            ? (int) $this->overage_rate_points
            : null;

        // Deprecated: superseded by price_display/currency above, which
        // this pair simply mirrors — kept for one release only because the
        // member app may still read the old names (docs/superpowers/specs/
        // 2026-09-12-add-point-b-pricing-design.md §3). Emitted only when
        // the resolved currency differs from base AND actually converted
        // (i.e. an exchange rate exists), matching the original
        // pre-points behaviour. Deliberately NOT computed on a separate
        // conversion path: both fields are read straight off the
        // price_display/currency values already derived above, so they
        // cannot drift from them. Remove after one release.
        if ($targetCurrency !== $baseCurrency && $convertedDisplay !== null) {
            $data['converted_amount'] = $data['price_display'];
            $data['converted_currency'] = $data['currency'];
        }

        return $data;
    }
}
