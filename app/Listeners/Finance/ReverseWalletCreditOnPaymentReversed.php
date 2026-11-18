<?php

namespace App\Listeners\Finance;

use App\Domain\Finance\Models\Payment;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\WalletTransaction;
use App\Events\Finance\PaymentReversed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Takes back the points a now-reversed payment granted.
 *
 * Written as a compensating debit rather than by deleting the original
 * credit: the ledger is append-only in spirit — every other balance change
 * in this app is a row, and a receipt that has already been shown to a
 * member must not evaporate from their history.
 *
 * The debit can legitimately drive the balance negative, and that is
 * allowed here on purpose. The member has already spent points that the
 * bank has now taken back; refusing the debit would leave the app's books
 * claiming value that no longer exists. `WalletService::debitGeneral()` is
 * deliberately NOT used for this reason — it refuses an overdraft, which
 * is right for spending and wrong for a clawback.
 *
 * A membership the payment activated is NOT cancelled here.
 * `MembershipStatus` has only `Active` and its own docblock forbids adding
 * cases with no writer, so inventing a cancellation path would be a much
 * larger change than a reversal listener should make. The membership is
 * logged for an operator to handle instead.
 */
class ReverseWalletCreditOnPaymentReversed
{
    public function handle(PaymentReversed $event): void
    {
        $payment = $event->payment;

        if (! $payment->hasBeenCredited()) {
            // Nothing was ever credited — an accepted payment reversed
            // before fulfilment ran. There is nothing to claw back.
            return;
        }

        if ($this->alreadyClawedBack($payment)) {
            // Belt and braces, mirroring the credit listener's own guard.
            // Nothing should deliver this event twice, but a clawback that
            // ran twice would silently take double the points off a member
            // — the kind of error nobody reports and everybody notices
            // later, so it is cheaper to check than to trust.
            Log::channel('paymera')->info('[Paymera] clawback skipped, already applied', [
                'payment_id' => $payment->id,
            ]);

            return;
        }

        $this->clawBack($payment);

        if ($payment->membership_id !== null) {
            Log::channel('paymera')->alert('[Paymera] reversed payment had activated a membership', [
                'payment_id' => $payment->id,
                'membership_id' => $payment->membership_id,
                'user_id' => $payment->user_id,
                'action_required' => 'membership remains active; cancel it manually if appropriate',
            ]);
        }
    }

    /**
     * Whether this payment's clawback row already exists. Matched on the
     * payment's own gateway reference in the description, which is what
     * makes the row attributable to this payment and no other.
     */
    private function alreadyClawedBack(Payment $payment): bool
    {
        return WalletTransaction::query()
            ->where('wallet_id', $payment->wallet_id)
            ->where('source', WalletTransactionSource::Refund)
            ->where('description', $this->clawBackDescription($payment))
            ->exists();
    }

    private function clawBackDescription(Payment $payment): string
    {
        return __('api.payment.reversal_description', ['reference' => $payment->payment_id]);
    }

    private function clawBack(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            WalletTransaction::create([
                'wallet_id' => $payment->wallet_id,
                'amount' => -$payment->points_to_credit,
                'category' => WalletTransactionCategory::General,
                'restricted_space_id' => null,
                'source' => WalletTransactionSource::Refund,
                'expires_at' => null,
                'description' => $this->clawBackDescription($payment),
            ]);
        });

        Log::channel('paymera')->info('[Paymera] points clawed back after reversal', [
            'payment_id' => $payment->id,
            'points' => $payment->points_to_credit,
            'wallet_id' => $payment->wallet_id,
        ]);
    }
}
