<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Events\Finance\PaymentAccepted;
use App\Events\Finance\PaymentFailed;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The ONE place a payment's outcome is decided and recorded. The member
 * returning to callbackURL, Paymera hitting triggerURL, and the
 * `paymera:reconcile` command all funnel through here — none of them
 * decides anything itself.
 *
 * That matters because neither the callback nor the trigger carries a
 * result. A member can reach callbackURL by pressing Cancel; Paymera fires
 * triggerURL when the hosted page finishes, whatever the outcome. Treating
 * either as "paid" is the classic way to give away goods for free. The
 * only trustworthy statement is get-payment-status, which is what this
 * asks for.
 *
 * Three defences against paying out wrongly, in order:
 *
 *  1. **A lock**, so two concurrent syncs (callback and trigger racing,
 *     which is the normal case rather than the exception) cannot both
 *     process the same payment.
 *  2. **A finality check** inside the lock, so a settled payment is never
 *     re-processed and its side effects never run twice.
 *  3. **Amount and terminal verification**, so an accepted payment that
 *     does not match what we recorded is quarantined as NeedsReview
 *     instead of credited.
 */
class SyncPaymentStatus
{
    private const LOCK_SECONDS = 30;

    private const LOCK_WAIT_SECONDS = 10;

    public function __construct(private readonly PaymeraClient $client) {}

    /**
     * Brings the local record in line with the gateway. Returns the payment
     * in whatever state it ended up in — already-final payments come back
     * untouched.
     */
    public function handle(Payment $payment): Payment
    {
        // A payment whose create-payment call never returned an id has
        // nothing to ask about.
        if ($payment->payment_id === null) {
            return $payment;
        }

        if ($payment->status->isFinal()) {
            return $payment;
        }

        $lock = Cache::lock("paymera:payment:{$payment->id}", self::LOCK_SECONDS);

        try {
            // Waits rather than bailing: when the callback and the trigger
            // arrive together, the loser should return the settled result,
            // not a stale "still pending".
            $lock->block(self::LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            Log::channel('paymera')->warning('[Paymera] sync skipped, lock busy', [
                'payment_id' => $payment->id,
            ]);

            return $payment->refresh();
        }

        try {
            // Re-read inside the lock. The holder we waited on may have just
            // settled this payment, and acting on the pre-lock snapshot is
            // precisely the double-processing this guards against.
            $payment->refresh();

            if ($payment->status->isFinal()) {
                return $payment;
            }

            return $this->process($payment);
        } finally {
            $lock->release();
        }
    }

    private function process(Payment $payment): Payment
    {
        $result = $this->client->getPaymentStatus($payment->payment_id);

        $payment->forceFill([
            'last_checked_at' => now(),
            'check_attempts' => $payment->check_attempts + 1,
            'raw_response' => $result->raw,
            'rrn' => $result->rrn ?? $payment->rrn,
            'gateway_created_at' => $payment->gateway_created_at ?? $this->parseTimestamp($result->creationTimestamp),
        ])->save();

        // Still in flight — nothing to decide yet. The reconciler will ask
        // again, and will eventually expire it if it never resolves.
        if ($result->status === PaymentStatus::Pending) {
            return $payment;
        }

        if ($result->status !== PaymentStatus::Accepted) {
            return $this->settleUnsuccessful($payment, $result->status);
        }

        // Accepted by the gateway. Before a single point is credited, the
        // payment has to be the one we actually created.
        if ($mismatch = $this->verify($payment, $result->amountMinor, $result->terminalId)) {
            return $this->quarantine($payment, $mismatch);
        }

        return $this->settleAccepted($payment);
    }

    /**
     * Returns a description of the mismatch, or null when everything checks
     * out. Both comparisons are strict: `terminalId` is normalized to a
     * string by the DTO because Paymera's own examples type it
     * inconsistently, and a loose `==` between 99990001 and "99990001"
     * would quietly pass anything numeric-looking.
     */
    private function verify(Payment $payment, int $amountMinor, string $terminalId): ?string
    {
        if ($amountMinor !== $payment->amount_minor) {
            return sprintf(
                'amount mismatch: gateway reported %d, expected %d',
                $amountMinor,
                $payment->amount_minor
            );
        }

        if ($terminalId !== (string) $payment->terminal_id) {
            return sprintf(
                'terminal mismatch: gateway reported "%s", expected "%s"',
                $terminalId,
                $payment->terminal_id
            );
        }

        return null;
    }

    /**
     * An accepted payment we cannot vouch for. Nothing is credited and no
     * success event fires — a human decides what happened.
     */
    private function quarantine(Payment $payment, string $reason): Payment
    {
        $payment->forceFill([
            'status' => PaymentStatus::NeedsReview,
            'review_reason' => $reason,
        ])->save();

        // Deliberately `alert`: an accepted payment that does not match our
        // record means either a bug or tampering, and both want a human.
        Log::channel('paymera')->alert('[Paymera] accepted payment failed verification', [
            'payment_id' => $payment->id,
            'gateway_payment_id' => $payment->payment_id,
            'reason' => $reason,
        ]);

        return $payment;
    }

    /**
     * Marks the payment accepted and fires the event that credits it. The
     * status write and the event are in one transaction so a listener that
     * throws cannot leave a payment marked accepted with nothing credited.
     */
    private function settleAccepted(Payment $payment): Payment
    {
        try {
            DB::transaction(function () use ($payment) {
                $payment->forceFill(['status' => PaymentStatus::Accepted])->save();

                // Dispatched inside the transaction, and the listener is
                // synchronous on purpose: crediting the wallet is not something
                // that may silently fail in a queue while the payment already
                // reads as accepted.
                event(new PaymentAccepted($payment));
            });
        } catch (\Throwable $e) {
            // The transaction rolled the Accepted write back, so the payment
            // is still Pending and the reconciler will try again — which is
            // right for a transient fault (a deadlock, a dropped connection)
            // and wrong to do silently for a persistent one. The realistic
            // persistent case is a missing exchange rate when charging in a
            // non-base currency: every retry then fails identically, and
            // without this line the member's paid-for points would never
            // arrive and nothing would say why.
            Log::channel('paymera')->alert('[Paymera] accepted payment could not be fulfilled', [
                'payment_id' => $payment->id,
                'gateway_payment_id' => $payment->payment_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $payment->refresh();
    }

    private function settleUnsuccessful(Payment $payment, PaymentStatus $status): Payment
    {
        $payment->forceFill(['status' => $status])->save();

        event(new PaymentFailed($payment));

        return $payment;
    }

    /**
     * Paymera sends `2022-01-26 12:06:52.547`. A malformed value must not
     * take down a sync that is otherwise fine — the timestamp is
     * informational.
     */
    private function parseTimestamp(?string $timestamp): ?Carbon
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        try {
            return Carbon::parse($timestamp);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The reconciler's write-off path for a payment that has sat at Pending
     * past its expiry: one last status check (via handle()), and if it is
     * still pending, it is closed out rather than polled forever.
     */
    public function expire(Payment $payment): Payment
    {
        $payment = $this->handle($payment);

        if ($payment->status !== PaymentStatus::Pending) {
            return $payment;
        }

        $payment->forceFill([
            'status' => PaymentStatus::Expired,
            'review_reason' => 'still pending after '.config('paymera.pending_expiry_minutes').' minutes',
        ])->save();

        Log::channel('paymera')->warning('[Paymera] payment expired while pending', [
            'payment_id' => $payment->id,
            'gateway_payment_id' => $payment->payment_id,
        ]);

        return $payment;
    }
}
