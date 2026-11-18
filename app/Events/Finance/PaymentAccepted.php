<?php

namespace App\Events\Finance;

use App\Domain\Finance\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A card payment has been confirmed by get-payment-status AND verified
 * against what we recorded. Only SyncPaymentStatus fires this, and only
 * after both checks pass — a listener may treat it as "the money is ours".
 *
 * Fires exactly once per payment: the finality check in SyncPaymentStatus
 * means a settled payment is never re-processed, however many times the
 * callback, the trigger and the reconciler ask about it.
 */
class PaymentAccepted
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment) {}
}
