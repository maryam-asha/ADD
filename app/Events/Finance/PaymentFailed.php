<?php

namespace App\Events\Finance;

use App\Domain\Finance\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The gateway reported a payment as failed or cancelled. Nothing was
 * charged, so there is nothing to undo — this exists for notification and
 * analytics, not for compensation.
 */
class PaymentFailed
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment) {}
}
