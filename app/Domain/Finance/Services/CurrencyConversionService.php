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
    public function convert(float $amount, string $fromCurrency, string $toCurrency): ?float
    {
        if ($fromCurrency === $toCurrency) {
            return $amount;
        }

        $baseCurrency = Currency::query()->where('is_base', true)->value('code');

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
