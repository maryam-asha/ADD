<?php

namespace App\Listeners\Finance;

use App\Domain\Finance\Payments\PaymentService;
use App\Events\Finance\PaymentAccepted;

/**
 * Turns a verified card payment into points, and spends them on what it
 * was for — through PaymentService, the one place any payment does that.
 * The rules (the credit is idempotent; a purchase that cannot be completed
 * leaves the points with the member and flags the payment for review) live
 * in PaymentService::fulfilAcceptedGatewayPayment().
 *
 * Synchronous, not queued. A queued listener would let a payment read as
 * Accepted while the credit sat in a failed job nobody notices; the money
 * has already been taken, so the ledger write happens now, in the same
 * request, inside the caller's transaction.
 *
 * Registered by event discovery only — never also by hand, which once made
 * it (and the reversal listener) fire twice.
 */
class FulfilAcceptedPayment
{
    public function __construct(private readonly PaymentService $payments) {}

    public function handle(PaymentAccepted $event): void
    {
        $this->payments->fulfilAcceptedGatewayPayment($event->payment);
    }
}
