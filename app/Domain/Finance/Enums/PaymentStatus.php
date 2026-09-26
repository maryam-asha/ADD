<?php

namespace App\Domain\Finance\Enums;

/**
 * The lifecycle of a card payment. Paymera itself reports only four states
 * (P/A/F/C); the remaining three are ours, for outcomes the gateway has no
 * opinion about:
 *
 *  - `Reversed` — accepted, then cancelled by an admin through
 *    ReversePayment. Distinct from `Canceled`, which means the CARDHOLDER
 *    abandoned the payment before it ever succeeded.
 *  - `Expired` — left at P past `paymera.pending_expiry_minutes` and still
 *    P on one final check. Nobody is coming back for it.
 *  - `NeedsReview` — something a human must look at: the gateway reported
 *    an accepted payment whose amount or terminal does not match what we
 *    recorded, or the post-credit side effect failed. Never reached
 *    automatically from a clean success.
 *
 * `isFinal()` is what prevents double processing: SyncPaymentStatus refuses
 * to re-process a payment already in one of these states.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case Reversed = 'reversed';
    case Expired = 'expired';
    case NeedsReview = 'needs_review';

    /**
     * Maps Paymera's single-letter `status` onto ours. Anything unknown is
     * treated as needing review rather than guessed at — an unrecognised
     * state must never silently read as a failure (which would strand a
     * real payment) or a success (which would credit an unpaid one).
     */
    public static function fromGatewayStatus(string $status): self
    {
        return match (strtoupper($status)) {
            'P' => self::Pending,
            'A' => self::Accepted,
            'F' => self::Failed,
            'C' => self::Canceled,
            default => self::NeedsReview,
        };
    }

    /**
     * A settled outcome. SyncPaymentStatus never re-processes one of these,
     * which is what makes repeated callback/trigger/reconcile hits idempotent.
     */
    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }

    /**
     * Only an accepted payment can be reversed at the bank — a pending or
     * already-reversed one has nothing to give back.
     */
    public function isReversible(): bool
    {
        return $this === self::Accepted;
    }
}
