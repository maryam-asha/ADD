<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\ExchangeRate;

/**
 * Raw numeric conversion only — no locale/display formatting (that's a
 * separate, later round). USD/SYP is the only pair `exchange_rates`
 * models (docs/superpowers/plans/2026-08-09-display-currency.md).
 */
class CurrencyConversionService
{
    public function convert(float $amount, string $fromCurrency, string $toCurrency): ?float
    {
        if ($fromCurrency === $toCurrency) {
            return $amount;
        }

        $rate = ExchangeRate::current();

        if ($rate === null) {
            return null;
        }

        $rateUsdToSyp = (float) $rate->rate_usd_to_syp;

        if ($fromCurrency === 'USD' && $toCurrency === 'SYP') {
            return round($amount * $rateUsdToSyp, 2);
        }

        if ($fromCurrency === 'SYP' && $toCurrency === 'USD') {
            return round($amount / $rateUsdToSyp, 2);
        }

        return null;
    }
}
