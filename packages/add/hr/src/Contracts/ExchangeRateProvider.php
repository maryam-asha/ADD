<?php

namespace Add\Hr\Contracts;

/**
 * Exchange rates for payroll. A run fixes the rates it used when it is
 * calculated, so a payslip never moves with the market afterwards.
 */
interface ExchangeRateProvider
{
    /**
     * How many units of `$to` one unit of `$from` buys, as a decimal string,
     * or null when the host has no rate.
     */
    public function rate(string $from, string $to): ?string;
}
