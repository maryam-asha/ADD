<?php

namespace App\Domain\Finance\Payments;

use App\Domain\Finance\Models\Payment;

/**
 * What reception's settlement did: the payment, the points the money bought,
 * the points spent on what was due, and what was left over — which stays in
 * the member's wallet as points.
 */
final readonly class SettledAtReception
{
    public function __construct(
        public Payment $payment,
        public int $pointsCredited,
        public int $pointsCharged,
    ) {}

    public function changePoints(): int
    {
        return $this->pointsCredited - $this->pointsCharged;
    }
}
