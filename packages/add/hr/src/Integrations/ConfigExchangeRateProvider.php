<?php

namespace Add\Hr\Integrations;

use Add\Hr\Contracts\ExchangeRateProvider;
use Add\Hr\Support\Decimal;

/**
 * The default: rates typed into hr.payroll.exchange_rates as "FROM:TO" =>
 * rate; the inverse pair is derived.
 */
class ConfigExchangeRateProvider implements ExchangeRateProvider
{
    public function rate(string $from, string $to): ?string
    {
        if ($from === $to) {
            return '1';
        }

        $rates = (array) config('hr.payroll.exchange_rates', []);

        if (isset($rates["{$from}:{$to}"])) {
            return Decimal::of($rates["{$from}:{$to}"]);
        }

        return isset($rates["{$to}:{$from}"]) ? Decimal::div('1', $rates["{$to}:{$from}"]) : null;
    }
}
