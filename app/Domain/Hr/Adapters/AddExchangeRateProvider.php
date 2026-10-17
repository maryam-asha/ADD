<?php

namespace App\Domain\Hr\Adapters;

use Add\Hr\Contracts\ExchangeRateProvider;
use Add\Hr\Support\Decimal;
use App\Domain\Finance\Services\CurrencyConversionService;

/**
 * Payroll converts at ADD's own admin-managed rates (Finance), through the
 * base currency like every other conversion in ADD. The run keeps the rates
 * it used, so a later rate change never moves an issued payslip.
 */
class AddExchangeRateProvider implements ExchangeRateProvider
{
    public function __construct(private readonly CurrencyConversionService $conversion) {}

    public function rate(string $from, string $to): ?string
    {
        if ($from === $to) {
            return '1';
        }

        $fromToBase = $this->toBase($from);
        $toToBase = $this->toBase($to);

        if ($fromToBase === null || $toToBase === null || Decimal::cmp($toToBase, '0') <= 0) {
            return null;
        }

        return Decimal::div($fromToBase, $toToBase);
    }

    /**
     * Units of the base currency per one unit of `$currency`.
     */
    private function toBase(string $currency): ?string
    {
        return $currency === $this->conversion->baseCurrencyCode() ? '1' : $this->conversion->rateToBase($currency);
    }
}
