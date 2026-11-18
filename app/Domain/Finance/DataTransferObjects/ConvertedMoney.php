<?php

namespace App\Domain\Finance\DataTransferObjects;

/**
 * What an amount of money handed over is worth in the ledger, and the rates
 * that made it so — everything a credit's audit columns need
 * (ReceivedMoneyConverter::toPoints()).
 *
 *  - `points`: whole points, floored (a credit never exceeds the money).
 *  - `baseAmount`: the money's base-currency value, cut to cents, for
 *    display. The points are computed from the exact value, not from this.
 *  - `rateToBase`: the exchange rate applied, null for the base currency.
 *  - `pointRateUsd`: the point rate at the moment of conversion.
 */
final readonly class ConvertedMoney
{
    public function __construct(
        public int $points,
        public string $baseAmount,
        public ?string $rateToBase,
        public string $pointRateUsd,
    ) {}
}
