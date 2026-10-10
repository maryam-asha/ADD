<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Models\ExchangeRate;

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
        return Currency::query()->where('is_base', true)->value('code') ?? 'USD';
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

    /**
     * The exact base-currency value of an amount, as a bcmath string — no
     * rounding at all. For the top-up credit path, where the result is
     * floored into points: rounding to cents first, as convert() does, can
     * lift it across a point boundary and credit more than was paid.
     * Null when the currency has no current rate, matching convert().
     */
    public function toBaseExact(string $amount, string $fromCurrency): ?string
    {
        if ($fromCurrency === $this->baseCurrencyCode()) {
            return $amount;
        }

        $rate = ExchangeRate::current($fromCurrency);

        if ($rate === null) {
            return null;
        }

        return bcmul($amount, (string) $rate->rate_to_base, 20);
    }
}
