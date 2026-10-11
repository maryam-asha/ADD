<?php

namespace App\Console\Commands;

use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\PaymentMethodRegistry;
use App\Domain\Finance\Services\SyncPaymentStatus;
use Illuminate\Console\Command;

/**
 * The safety net under every other path.
 *
 * A payment can be left pending by things no callback or trigger will ever
 * fix: the member closed the browser before returning, the trigger request
 * was lost, the queue worker was down when it fired, the app was
 * redeploying. Without this, that payment stays pending forever while the
 * member's money sits at the bank.
 *
 * Runs every five minutes (routes/console.php). Each pending payment is
 * re-checked against its gateway; one that has sat past
 * `paymera.pending_expiry_minutes` gets one final check and is then
 * written off as expired rather than polled indefinitely.
 *
 * Despite the name, this covers every payment whose method is a gateway
 * (see PaymentMethodRegistry::gatewayKeys()). The command keeps its name
 * and schedule because deployed cron entries and runbooks call it by it.
 * A pending payment with no gateway behind it — cash awaiting the desk —
 * has nobody to ask, and is left alone.
 *
 * Every payment is handled through SyncPaymentStatus, which locks and
 * re-checks finality — so this command overlapping with a callback, a
 * trigger job, or a previous run of itself cannot double-credit anything.
 */
class ReconcilePaymeraPayments extends Command
{
    protected $signature = 'paymera:reconcile';

    protected $description = 'Re-check every pending gateway (Paymera) payment against the gateway, and expire the ones that never resolved.';

    public function handle(SyncPaymentStatus $sync, PaymentMethodRegistry $registry): int
    {
        $expiryMinutes = (int) config('paymera.pending_expiry_minutes');
        $cutoff = now()->subMinutes($expiryMinutes);

        $checked = 0;
        $expired = 0;
        $settled = 0;

        Payment::query()
            ->pending()
            // Only payments a gateway can answer for.
            ->whereIn('method', $registry->gatewayKeys())
            // A payment that never got an id from create-payment has nothing
            // to ask the gateway about.
            ->whereNotNull('payment_id')
            ->orderBy('created_at')
            ->chunkById(100, function ($payments) use ($sync, $cutoff, &$checked, &$expired, &$settled) {
                foreach ($payments as $payment) {
                    $checked++;

                    try {
                        $result = $payment->created_at !== null && $payment->created_at->lt($cutoff)
                            ? $sync->expire($payment)
                            : $sync->handle($payment);

                        if ($result->status->value === 'expired') {
                            $expired++;
                        } elseif ($result->status->isFinal()) {
                            $settled++;
                        }
                    } catch (\Throwable $e) {
                        // One unreachable payment must not abort the sweep —
                        // the rest still need checking, and this one will be
                        // picked up again in five minutes.
                        $this->error("Payment {$payment->id}: {$e->getMessage()}");
                    }
                }
            });

        $this->info("Checked {$checked} pending payment(s): {$settled} settled, {$expired} expired.");

        return self::SUCCESS;
    }
}
