<?php

namespace App\Support\Presenters;

use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Services\CurrencyResolver;
use App\Domain\Finance\Services\PointConversionService;
use App\Domain\Identity\Models\User;
use Illuminate\Http\Request;

/**
 * Display-ready price labels derived from a point price: the amount in the
 * caller's currency with that currency's symbol, and the points themselves.
 *
 * Points remain the only stored price (point system Invariant 1); the
 * currency amount is derived at read time, in the currency the request asks
 * for — `currency` header, else the member's preference, else the base
 * currency (CurrencyResolver).
 */
class PriceLabelPresenter
{
    /** @var array<string, Currency|null> */
    private array $currencyRows = [];

    public function __construct(
        private readonly PointConversionService $points,
        private readonly CurrencyResolver $currencies,
    ) {}

    /**
     * A space's hourly rate, e.g. `3.00 $ / hour` and `30 pts / hour`.
     *
     * @return array{price: string, price_point: string}
     */
    public function hourly(int $points, ?User $user): array
    {
        $perHour = __('api.space.per_hour');
        [$amount, $currency] = $this->amountFor($points, $user);

        return [
            'price' => $this->currencyLabel($amount, $currency).' / '.$perHour,
            'price_point' => __('api.space.price_points', ['points' => number_format($points)]).' / '.$perHour,
        ];
    }

    /**
     * A whole amount due, e.g. `10.00 $` and `100 pts`.
     *
     * @return array{price: string, price_point: string}
     */
    public function total(int $points, ?User $user): array
    {
        [$amount, $currency] = $this->amountFor($points, $user);

        return [
            'price' => $this->currencyLabel($amount, $currency),
            'price_point' => __('api.space.price_points', ['points' => number_format($points)]),
        ];
    }

    /** The resolved display currency for this request and member. */
    public function currencyFor(?User $user): string
    {
        return $this->currencies->resolve(request() ?? new Request, $user);
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
    public function currencyLabel(string $amount, string $currencyCode): string
    {
        // Memoised for the request: a listing formats a price per card.
        $currency = $this->currencyRows[$currencyCode] ??= Currency::find($currencyCode);

        $decimals = $currency?->decimal_places ?? 2;
        $symbol = $currency?->symbol ?: $currencyCode;

        return number_format((float) $amount, $decimals).' '.$symbol;
    }

    /**
     * The points in the display currency — or in USD, labelled as USD, when
     * that currency has no rate yet.
     *
     * @return array{0: string, 1: string} amount, currency code
     */
    private function amountFor(int $points, ?User $user): array
    {
        $currency = $this->currencyFor($user);
        $amount = $this->points->pointsToCurrency($points, $currency);

        return $amount === null ? [$this->points->pointsToUsd($points), 'USD'] : [$amount, $currency];
    }
}
