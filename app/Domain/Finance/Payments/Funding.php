<?php

namespace App\Domain\Finance\Payments;

use App\Domain\Finance\Enums\PaymentMethod;

/**
 * Money that arrived for a payment and becomes points before anything is
 * spent: the credit PaymentService::settle() writes first, with the audit
 * columns every point credit carries (Invariant 3) — how the money came,
 * in what currency and amount, at which exchange and point rates, and who
 * took it (null when nobody did by hand).
 *
 * `amount` is in `currency`, never converted: the two are read as a pair.
 */
final readonly class Funding
{
    public function __construct(
        public int $points,
        public ?PaymentMethod $method = null,
        public ?string $currency = null,
        public ?string $amount = null,
        public ?string $rateToBase = null,
        public ?string $pointRateUsd = null,
        public ?int $performedBy = null,
    ) {}
}
