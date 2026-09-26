<?php

namespace App\Listeners\Finance;

use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Services\PaymentAmountCalculator;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Services\MembershipActivationService;
use App\Domain\Membership\Services\WalletService;
use App\Events\Finance\PaymentAccepted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns a verified card payment into points, and — for a plan purchase —
 * into an active membership.
 *
 * Synchronous, not queued. A queued listener would let a payment read as
 * Accepted while the credit sat in a failed job nobody notices; the money
 * has already been taken, so the ledger write happens now, in the same
 * request, inside the caller's transaction.
 *
 * Two rules from docs/paymera-integration-plan.md are implemented here:
 *
 *  - **Credit is idempotent.** It is skipped when the payment already
 *    links a wallet transaction, so a replayed event cannot double-credit.
 *    That link — not the status — is the record of "already paid out",
 *    because an accepted payment can legitimately move on to NeedsReview.
 *  - **Points survive a failed activation.** If the plan cannot be
 *    activated (deactivated, repriced, deleted between start and return),
 *    the member keeps the points and the payment is flagged for review.
 *    Their money is never silently absorbed.
 */
class FulfilAcceptedPayment
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly MembershipActivationService $activations,
        private readonly PaymentAmountCalculator $amounts,
    ) {}

    public function handle(PaymentAccepted $event): void
    {
        $payment = $event->payment;

        if ($payment->hasBeenCredited()) {
            Log::channel('paymera')->info('[Paymera] credit skipped, already applied', [
                'payment_id' => $payment->id,
                'wallet_transaction_id' => $payment->wallet_transaction_id,
            ]);

            return;
        }

        $this->credit($payment);

        if ($payment->purpose === PaymentPurpose::PlanPurchase) {
            $this->activatePlan($payment);
        }
    }

    /**
     * Writes the point credit and links it to the payment in one
     * transaction, so a credited wallet can never exist without the payment
     * recording that it was credited — which is what makes the idempotence
     * check above trustworthy.
     */
    private function credit(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $wallet = $payment->wallet;

            $transaction = $this->wallets->creditGeneral(
                $wallet,
                $payment->points_to_credit,
                WalletTransactionSource::TopUp,
                __('api.payment.credit_description', ['reference' => $payment->payment_id])
            );

            // The same audit columns the reception till fills in, so a card
            // credit and a cash credit are equally reconstructable.
            // `performed_by_user_id` stays null: nobody took this money by
            // hand. These columns are audit-only and never affect balance
            // computation (WalletBalanceIgnoresAuditColumnsTest).
            $transaction->forceFill([
                'payment_method' => PaymentMethod::Paymera,
                'payment_currency' => $payment->currency,
                'payment_amount' => $this->amounts->minorToUsd($payment->amount_minor),
                'point_rate_usd' => null,
            ])->save();

            $payment->forceFill(['wallet_transaction_id' => $transaction->id])->save();
        });

        Log::channel('paymera')->info('[Paymera] points credited', [
            'payment_id' => $payment->id,
            'points' => $payment->points_to_credit,
            'wallet_id' => $payment->wallet_id,
        ]);
    }

    /**
     * Activates the purchased plan, charging the points just credited.
     *
     * `charge: true` is correct and not a double charge: the card bought
     * points, and the plan is bought with those points — the same two-step
     * every member already goes through when they top up at reception and
     * then subscribe. It also means the plan's price is re-read from the
     * database at activation time, so a repriced plan is caught here rather
     * than silently granted at the old price.
     */
    private function activatePlan(Payment $payment): void
    {
        try {
            $plan = $payment->plan;

            if ($plan === null || ! $plan->is_active) {
                throw new \RuntimeException('plan is no longer available');
            }

            $membership = $this->activations->activate(
                $plan,
                OwnerType::User,
                $payment->user_id,
                charge: true,
                auditAction: 'membership_purchased_by_card'
            );

            $payment->forceFill(['membership_id' => $membership->id])->save();
        } catch (\Throwable $e) {
            // The money is already points in the member's wallet, and it
            // stays there. Flagging for review is the whole remedy — no
            // automatic bank reversal, by decision.
            $payment->forceFill([
                'status' => PaymentStatus::NeedsReview,
                'review_reason' => 'payment credited but plan activation failed: '.$e->getMessage(),
            ])->save();

            Log::channel('paymera')->alert('[Paymera] plan activation failed after successful payment', [
                'payment_id' => $payment->id,
                'plan_id' => $payment->plan_id,
                'points_credited' => $payment->points_to_credit,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
