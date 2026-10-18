<?php

namespace Add\Hr\Payroll\Calculation;

use Add\Hr\Contracts\ExchangeRateProvider;
use Add\Hr\Support\HrException;

/**
 * The rates into the run's currency, asked once per currency and kept on
 * the run, so every slip of the run converts alike.
 */
final class RunRates
{
    /** @var array<string, string> */
    private array $rates = [];

    public function __construct(
        private readonly string $currency,
        private readonly ExchangeRateProvider $provider,
    ) {}

    public function rate(string $currency): string
    {
        $currency = strtoupper($currency);

        if ($currency === $this->currency) {
            return '1';
        }

        return $this->rates[$currency] ??= $this->provider->rate($currency, $this->currency)
            ?? HrException::fail('hr::payroll.missing_rate', ['from' => $currency, 'to' => $this->currency]);
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->rates;
    }
}
