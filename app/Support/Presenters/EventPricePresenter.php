<?php

namespace App\Support\Presenters;

use App\Domain\Experience\Models\Event;
use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Services\CurrencyResolver;
use App\Domain\Finance\Services\PointConversionService;
use App\Domain\Identity\Models\User;
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
     * The member-facing view: the two labels a client prints, and nothing
     * else. A member never needs the raw point count — registration
     * charges the wallet server-side and validates the balance there, so
     * shipping the number would only invite a client to re-derive a price
     * the server already decided.
     *
     * Admin surfaces keep the full block (they edit the stored value).
     *
     * @return array{price_label: string, price_display_label: string}
     */
    public function presentForDisplay(Event $event, ?User $user, string $locale): array
    {
        $price = $this->presentFor($event, $user, $locale);

        return [
            'price_label' => $price['price_label'],
            'price_display_label' => $price['price_display_label'],
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

        $priceUsd = $this->points->pointsToUsd($pricePoints);
        $priceDisplay = $this->points->pointsToCurrency($pricePoints, $targetCurrency) ?? $priceUsd;

        return [
            'price_points' => $pricePoints,
            'price_label' => $isFree
                ? __('api.event.price_free', [], $locale)
                : __('api.event.price_points', ['points' => $pricePoints], $locale),
            'price_usd' => $priceUsd,
            'price_display' => $priceDisplay,
            'price_display_label' => $isFree
                ? __('api.event.price_free', [], $locale)
                : $this->withSymbol($priceDisplay, $targetCurrency),
            'currency' => $targetCurrency,
        ];
    }

    /**
     * Thousands-separated, with the currency's own symbol placed after the
     * number — the convention both `ل.س` and `$` read correctly in here,
     * and the one the dashboard already uses.
     *
     * Falls back to the currency code when a row carries no symbol, so an
     * admin adding a currency without one degrades to "1,000 EUR" rather
     * than a bare number.
     */
    private function withSymbol(string $amount, string $currencyCode): string
    {
        // Memoised for the request: a listing formats a price per card, and
        // this used to read the same currency row every time. The instance
        // is per-resolution, which is the life of one request here.
        $currency = $this->currencyRows[$currencyCode] ??= Currency::find($currencyCode);

        $decimals = $currency?->decimal_places ?? 2;
        $symbol = $currency?->symbol ?: $currencyCode;

        return number_format((float) $amount, $decimals).' '.$symbol;
    }

    /** @var array<string, Currency|null> */
    private array $currencyRows = [];
}
