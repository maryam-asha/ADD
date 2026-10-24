<?php

namespace App\Support\Presenters;

use App\Domain\Experience\Models\Event;
use App\Domain\Finance\Services\CurrencyResolver;
use App\Domain\Finance\Services\PointConversionService;
use App\Domain\Identity\Models\User;
use App\Support\PlainAmount;
use Illuminate\Http\Request;

/**
 * Builds the price block every event endpoint returns — the raw numbers
 * the client computes with, plus display-ready labels it can print
 * directly.
 *
 * Points remain the only stored price (point system Invariant 1); every
 * currency figure here is derived at read time. The labels exist because
 * three separate call sites were each formatting "50 pts" by hand, with
 * the currency equivalent missing entirely.
 */
class EventPricePresenter
{
    public function __construct(
        private readonly PointConversionService $points,
        private readonly CurrencyResolver $currencies,
        private readonly PriceLabelPresenter $labels,
    ) {}

    /**
     * Resolves the display currency itself (header -> preference -> base),
     * so callers inside Domain\Experience never have to import from
     * Domain\Finance — PRD decisions #17/#18 forbid that dependency, and
     * tests/Guards/DomainLayerBoundaryTest.php enforces it.
     *
     * @return array{
     *     price_points: int,
     *     price_label: string,
     *     price_usd: string,
     *     price_display: string,
     *     price_display_label: string,
     *     currency: string
     * }
     */
    public function presentFor(Event $event, ?User $user, string $locale): array
    {
        $request = request() ?? new Request;

        return $this->present($event, $this->currencies->resolve($request, $user), $locale);
    }

    /**
     * The member-facing view: points and price as numbers, `currency` the
     * symbol beside the price — shaped like a space's or a package's
     * pricing, its unit null: a ticket is not per anything. A free event is
     * `is_free` beside it.
     *
     * @return array{
     *     points: int,
     *     price: int|float,
     *     currency: string,
     *     unit: null,
     *     unit_label: null
     * }
     */
    public function presentForDisplay(Event $event, ?User $user, string $locale): array
    {
        $price = $this->presentFor($event, $user, $locale);
        $amount = $price['price_display'];
        $numericPrice = (float) $amount == (int) (float) $amount ? (int) (float) $amount : (float) $amount;

        return [
            'points' => $price['price_points'],
            'price' => $numericPrice,
            'currency' => $this->labels->currencySymbol($price['currency'], $locale),
            'unit' => null,
            'unit_label' => null,
        ];
    }

    /**
     * @return array{
     *     price_points: int,
     *     price_label: string,
     *     price_usd: string,
     *     price_display: string,
     *     price_display_label: string,
     *     currency: string
     * }
     */
    public function present(Event $event, string $targetCurrency, string $locale): array
    {
        $pricePoints = (int) ($event->price_points ?? 0);
        $isFree = (bool) ($event->is_free ?? true) || $pricePoints <= 0;

        $priceUsd = PlainAmount::of($this->points->pointsToUsd($pricePoints));
        $priceDisplay = PlainAmount::of($this->points->pointsToCurrency($pricePoints, $targetCurrency)) ?? $priceUsd;

        return [
            'price_points' => $pricePoints,
            'price_label' => $isFree
                ? __('api.event.price_free', [], $locale)
                : __('api.event.price_points', ['points' => $pricePoints], $locale),
            'price_usd' => $priceUsd,
            'price_display' => $priceDisplay,
            'price_display_label' => $isFree
                ? __('api.event.price_free', [], $locale)
                : $this->labels->currencyLabel($priceDisplay, $targetCurrency, $locale),
            'currency' => $targetCurrency,
        ];
    }
}
