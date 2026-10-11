<?php

namespace App\Jobs;

use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Services\SyncPaymentStatus;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Asks the gateway what happened to a payment, off the request cycle.
 *
 * This is what the trigger endpoint dispatches. The endpoint itself must
 * answer Paymera immediately — it is a notification, not a conversation —
 * so the actual status call happens here.
 *
 * `ShouldBeUnique` keyed on the payment id: the trigger can fire more than
 * once, and the reconciler may pick up the same payment concurrently.
 * Queueing several identical checks would be harmless (SyncPaymentStatus
 * locks and re-checks finality) but wasteful.
 */
class SyncPaymeraPaymentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * The gateway is usually seconds behind the hosted page, occasionally
     * minutes. Backing off rather than hammering it.
     *
     * @var list<int>
     */
    public array $backoff = [10, 30, 60, 120];

    /**
     * Stops a stuck job from being retried for hours; the reconcile command
     * is the backstop that will pick the payment up again anyway.
     */
    public int $uniqueFor = 600;

    public function __construct(public readonly int $paymentId) {}

    public function uniqueId(): string
    {
        return (string) $this->paymentId;
    }

    public function handle(SyncPaymentStatus $sync): void
    {
        $payment = Payment::find($this->paymentId);

        // Nothing to do for a payment that has since been deleted, or that
        // some other path (callback, reconciler) already settled.
        if ($payment === null || $payment->status->isFinal()) {
            return;
        }

        $sync->handle($payment);
    }
}
