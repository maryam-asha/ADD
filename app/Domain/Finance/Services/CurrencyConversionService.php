<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Models\ExchangeRate;
use App\Support\PlainAmount;

/**
 * Raw numeric conversion only — no locale/display formatting (that's a
 * separate, later round). Generalized from the original hardcoded USD/SYP
 * pair to N admin-managed currencies (docs/decisions/multi-currency-support.md):
 * every non-base currency carries its own `exchange_rates` row
 * (`rate_to_base`), and converting between two non-base currencies (e.g.
 * USD -> EUR) routes through the fixed base currency rather than requiring
 * a direct rate for every possible pair.
 */
class CurrencyConversionService
{
    public function baseCurrencyCode(): string
    {
        return Currency::baseCode();
    }

    /**
     * The current rate turning one unit of `$currencyCode` into the base
     * currency, as the exact decimal string the column holds — for callers
     * that must stay in bcmath rather than go through convert()'s floats.
     * Null when the currency has no active rate; the base currency itself
     * never has one (nothing to convert).
     */
    public function rateToBase(string $currencyCode): ?string
    {
        $rate = ExchangeRate::current($currencyCode);

        return $rate !== null ? (string) $rate->rate_to_base : null;
    }

    /**
     * How many units of `$currencyCode` one unit of the base currency buys —
     * the "1 USD = 140 SYP" figure people read, the reciprocal of what is
     * stored (rate_to_base is base per one unit of the currency). Rounded
     * half-up to the currency's own decimals, which also absorbs what the
     * stored 10-decimal reciprocal loses (1 / 0.0071428571 = 140.00000056).
     * Null when the currency has no current rate.
     */
    public function baseToCurrencyRate(string $currencyCode): ?string
    {
        if ($currencyCode === $this->baseCurrencyCode()) {
            return '1';
        }

        $rateToBase = $this->rateToBase($currencyCode);

        if ($rateToBase === null || bccomp($rateToBase, '0', 10) <= 0) {
            return null;
        }

        $decimals = (int) (Currency::query()->whereKey($currencyCode)->value('decimal_places') ?? 2);
        $half = '0.'.str_repeat('0', $decimals).'5';

        return PlainAmount::of(bcadd(bcdiv('1', $rateToBase, $decimals + 1), $half, $decimals));
    }

    public function convert(float $amount, string $fromCurrency, string $toCurrency): ?float
    {
        if ($fromCurrency === $toCurrency) {
            return $amount;
        }

        $baseCurrency = $this->baseCurrencyCode();

        if ($toCurrency === $baseCurrency) {
            $rate = ExchangeRate::current($fromCurrency);

            if ($rate === null) {
                return null;
            }

            return round($amount * (float) $rate->rate_to_base, 2);
        }

        if ($fromCurrency === $baseCurrency) {
            $rate = ExchangeRate::current($toCurrency);

            if ($rate === null) {
                return null;
            }

            return round($amount / (float) $rate->rate_to_base, 2);
        }

        $fromRate = ExchangeRate::current($fromCurrency);
        $toRate = ExchangeRate::current($toCurrency);

        if ($fromRate === null || $toRate === null) {
            return null;
        }

        $amountInBase = $amount * (float) $fromRate->rate_to_base;

        return round($amountInBase / (float) $toRate->rate_to_base, 2);
    }
}
