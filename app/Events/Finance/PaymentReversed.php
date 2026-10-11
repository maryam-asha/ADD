<?php

namespace App\Events\Finance;

use App\Domain\Finance\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An accepted payment was reversed at the bank by an admin. Listeners undo
 * whatever the acceptance granted — the points credited, and any membership
 * the payment activated.
 */
class PaymentReversed
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment) {}
}
