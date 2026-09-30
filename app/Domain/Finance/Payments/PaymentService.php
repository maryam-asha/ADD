<?php

namespace App\Domain\Finance\Payments;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Contracts\PaymentFulfiller;
use App\Domain\Finance\Payments\Exceptions\InsufficientPointsException;
use App\Domain\Finance\Payments\Methods\PaymeraGateway;
use App\Domain\Finance\Services\PaymentAmountCalculator;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Exceptions\InsufficientBalanceException;
use App\Domain\Membership\Models\WalletTransaction;
use App\Domain\Membership\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one place a payment turns into points and the points are spent on
 * what was bought — whatever the method, whatever the purpose.
 *
 * Points are the only ledger. Money that arrives (a card, cash at the desk)
 * is first credited to the payment's wallet as points, with the audit
 * columns saying how it came; then what is due is debited from the same
 * wallet in the fulfiller's category; then the fulfiller delivers. A plan
 * bought by card is therefore a credit and a debit, net zero — the same two
 * steps as a top-up followed by a purchase.
 *
 * Two idempotence links on the payment row make every step safe to repeat:
 * `wallet_transaction_id` records that the money was credited,
 * `debit_transaction_id` that the points were spent. They — not `status` —
 * are the record, because an accepted payment can legitimately move on to
 * NeedsReview.
 */
class PaymentService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly FulfillerRegistry $fulfillers,
        private readonly PaymentMethodRegistry $methods,
        private readonly PaymentAmountCalculator $amounts,
    ) {}

    /**
     * Settles a payment in one transaction, with its row locked: credits
     * `$funding` (when money arrived), spends what is due (unless it is a
     * top-up, which only buys the points), fulfils, and marks it Accepted.
     *
     * Settling a payment that is already settled returns it untouched. Any
     * failure — the target can no longer be bought, the wallet is short,
     * fulfilment throws — rolls the whole settlement back, credit included.
     *
     * @throws InsufficientPointsException when the wallet cannot cover what is due
     * @throws ReceptionActionException when the fulfiller refuses the target
     */
    public function settle(Payment $payment, ?Funding $funding = null): Payment
    {
        $fulfiller = $this->fulfillers->for($payment->purpose);

        return DB::transaction(function () use ($payment, $funding, $fulfiller) {
            $this->lock($payment);

            if ($this->isSettled($payment)) {
                return $payment;
            }

            if ($funding !== null && ! $payment->hasBeenCredited()) {
                $this->credit($payment, $funding, __('api.payment.received_description'));
            }

            if ($payment->purpose !== PaymentPurpose::WalletTopUp) {
                $fulfiller->assertPayable($payment);
                $this->spend($payment, $fulfiller);
            }

            $fulfiller->fulfil($payment);

            $payment->forceFill(['status' => PaymentStatus::Accepted])->save();

            return $payment;
        });
    }

    /**
     * Turns a card payment the gateway has just accepted into points, and
     * spends them on what it was for. Called synchronously, inside
     * SyncPaymentStatus's transaction, by the FulfilAcceptedPayment listener.
     *
     * Two phases, deliberately apart:
     *
     *  1. **The credit** — idempotent on `wallet_transaction_id`. The money
     *     is at the bank, so the points must reach the wallet whatever
     *     happens next; a failure here propagates, the sync rolls back, and
     *     the reconciler tries again.
     *  2. **The spend and the fulfilment** — settle(), idempotent on
     *     `debit_transaction_id`, in its own (nested) transaction. If it
     *     throws — a plan withdrawn or repriced, a full seat pool — only it
     *     rolls back: the points stay with the member, the payment becomes
     *     NeedsReview and an alert goes out. Their money is never silently
     *     absorbed, and no bank reversal is attempted (a human decides).
     */
    public function fulfilAcceptedGatewayPayment(Payment $payment): void
    {
        if ($payment->hasBeenCredited()) {
            $this->log($payment, 'info', 'credit skipped, already applied', [
                'payment_id' => $payment->id,
                'wallet_transaction_id' => $payment->wallet_transaction_id,
            ]);
        } else {
            $this->creditGatewayPayment($payment);
        }

        $this->spendGatewayCredit($payment);
    }

    /**
     * Phase 1. The same audit values the card flow has always written: the
     * gateway's ledger method, the amount in the currency actually charged
     * (not its USD value — the two columns are read as a pair), the rate
     * that re-derives the USD value later, no point rate, and nobody's hand.
     */
    private function creditGatewayPayment(Payment $payment): void
    {
        $funding = new Funding(
            points: $payment->points_to_credit,
            method: $this->methods->gatewayFor($payment)->ledgerMethod(),
            currency: $payment->currency,
            amount: $this->amounts->minorToCharged($payment->amount_minor),
            rateToBase: $this->amounts->appliedExchangeRate(),
            pointRateUsd: null,
            performedBy: null,
        );

        DB::transaction(fn () => $this->credit(
            $payment,
            $funding,
            __('api.payment.credit_description', ['reference' => $payment->payment_id]),
        ));

        $this->log($payment, 'info', 'points credited', [
            'payment_id' => $payment->id,
            'points' => $payment->points_to_credit,
            'wallet_id' => $payment->wallet_id,
        ]);
    }

    /**
     * Phase 2. Runs only for a payment still Accepted: one already under
     * review is a human's to resolve, and asking again must not quietly
     * retry the purchase behind their back.
     */
    private function spendGatewayCredit(Payment $payment): void
    {
        if ($payment->status !== PaymentStatus::Accepted) {
            return;
        }

        try {
            $this->settle($payment);
        } catch (Throwable $e) {
            // Re-read: the rolled-back settlement may have left values on
            // the model (a debit id) that never reached the database.
            $payment->refresh();

            $payment->forceFill([
                'status' => PaymentStatus::NeedsReview,
                'review_reason' => "payment credited but activation failed ({$payment->purpose->value}): ".$this->reasonFor($e),
            ])->save();

            $this->log($payment, 'alert', 'fulfilment failed after successful payment', [
                'payment_id' => $payment->id,
                'purpose' => $payment->purpose->value,
                'plan_id' => $payment->plan_id,
                'points_credited' => $payment->points_to_credit,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Writes the point credit, fills its audit columns, and links it to the
     * payment — in the caller's transaction, so a credited wallet can never
     * exist without the payment recording that it was credited. The audit
     * columns never affect a balance (WalletBalanceIgnoresAuditColumnsTest).
     */
    private function credit(Payment $payment, Funding $funding, string $description): WalletTransaction
    {
        $transaction = $this->wallets->creditGeneral(
            $payment->wallet,
            $funding->points,
            WalletTransactionSource::TopUp,
            $description,
        );

        $transaction->forceFill([
            'payment_method' => $funding->method,
            'payment_currency' => $funding->currency,
            'payment_amount' => $funding->amount,
            'exchange_rate_to_base' => $funding->rateToBase,
            'point_rate_usd' => $funding->pointRateUsd,
            'performed_by_user_id' => $funding->performedBy,
        ])->save();

        $payment->forceFill(['wallet_transaction_id' => $transaction->id])->save();

        return $transaction;
    }

    /**
     * Debits what is due from the payment's wallet, spent by the payer, and
     * records what was actually debited. A category debit can split across
     * several restriction pools; the payment links the first row.
     */
    private function spend(Payment $payment, PaymentFulfiller $fulfiller): void
    {
        $category = $fulfiller->walletCategory();
        $due = $fulfiller->pointsDue($payment);

        try {
            $rows = $this->wallets->debit($payment->wallet, $payment->user, $category, $due, $fulfiller->describe($payment));
        } catch (InsufficientBalanceException) {
            throw InsufficientPointsException::for($this->spendableBalance($payment, $category), $due);
        }

        $debited = '0';

        foreach ($rows as $row) {
            $debited = bcsub($debited, (string) $row->amount, 0);
        }

        $payment->forceFill([
            'debit_transaction_id' => $rows[0]->id ?? null,
            'amount_points' => (int) $debited,
        ])->save();
    }

    /**
     * What the debit could have drawn on: the general balance, or — for a
     * category debit, which falls back to general — the larger of the two.
     */
    private function spendableBalance(Payment $payment, WalletTransactionCategory $category): int
    {
        $general = $this->wallets->generalBalance($payment->wallet);

        if ($category === WalletTransactionCategory::General) {
            return $general;
        }

        return max($general, $this->wallets->categoryBalance($payment->wallet, $payment->user, $category));
    }

    /**
     * Locks the payment row and brings the model in line with it, so every
     * decision below is made on the locked, current state rather than on
     * whatever snapshot the caller held.
     */
    private function lock(Payment $payment): void
    {
        $current = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

        $payment->setRawAttributes($current->getAttributes(), true);
        $payment->setRelations([]);
    }

    /**
     * A top-up is settled once its credit landed; anything else once its
     * points were spent.
     */
    private function isSettled(Payment $payment): bool
    {
        if ($payment->status !== PaymentStatus::Accepted) {
            return false;
        }

        return $payment->purpose === PaymentPurpose::WalletTopUp
            ? $payment->hasBeenCredited()
            : $payment->debit_transaction_id !== null;
    }

    /**
     * `review_reason` is read by staff and written in English, like every
     * other reason on the row; a fulfiller's refusal carries a key.
     */
    private function reasonFor(Throwable $e): string
    {
        return $e instanceof ReceptionActionException
            ? (string) __($e->messageKey, $e->params, 'en')
            : $e->getMessage();
    }

    /**
     * Paymera's lines go to its dedicated `paymera` channel, where its
     * operators look; any other method logs to the default channel — the
     * same split SyncPaymentStatus makes.
     *
     * @param  array<string, mixed>  $context
     */
    private function log(Payment $payment, string $level, string $message, array $context = []): void
    {
        if ($payment->method === PaymeraGateway::KEY) {
            Log::channel('paymera')->{$level}("[Paymera] {$message}", $context);

            return;
        }

        Log::{$level}("[Payments] {$message}", $context);
    }
}
