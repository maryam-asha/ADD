<?php

namespace App\Support\Presenters;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Methods\WalletMethod;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\WalletTransaction;
use App\Support\Time\LocalTime;

/**
 * One row of the member's wallet history, as the app shows it.
 *
 * The stored `description` is a ledger note written in English by whatever
 * wrote the row ("Booking for space #3"), so it is not what the member
 * reads. The row is named instead from the payment it belongs to: `kind` is
 * what happened (a booking paid, a top-up, a refund...) and `title` adds
 * what it was for, in the request's language. A row no payment explains —
 * older debits, the extra rows of a split category debit — falls back to
 * its stored note.
 *
 * Every money figure is derived from the points, in the caller's display
 * currency (PriceLabelPresenter), except `paid`: the money actually handed
 * over or charged for a credit, verbatim from the row's audit columns. So
 * `amount` is what the points are worth at today's rate and drifts with it;
 * `paid` is fixed at the moment of payment, and null for a row no money
 * came with (a spend, a grant, a gift). A row shows what was paid by `paid`,
 * never by `amount`.
 *
 * Expects the relations WalletHistoryService loads.
 */
class WalletTransactionPresenter
{
    public function __construct(
        private readonly PriceLabelPresenter $prices,
        private readonly LocalTime $time,
    ) {}

    /**
     * A row of the history list.
     *
     * @return array<string, mixed>
     */
    public function item(WalletTransaction $transaction, ?User $viewer, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $payment = $transaction->payment();
        $kind = $this->kind($transaction, $payment);
        $method = $this->method($transaction, $kind);
        $at = $this->time->toLocal($transaction->created_at);

        return [
            'id' => $transaction->id,
            'type' => $transaction->amount < 0 ? 'debit' : 'credit',
            'kind' => $kind,
            'kind_label' => __("api.wallet.kinds.{$kind}", [], $locale),
            'title' => $this->title($transaction, $payment, $kind, $locale),
            // Signed, as stored: negative is a spend.
            'points' => $transaction->amount,
            // Today's value of the points, not what was paid — see `paid`.
            'amount' => $this->prices->total(abs($transaction->amount), $viewer, $locale),
            'paid' => $this->paid($transaction, $locale),
            'category' => $transaction->category->value,
            'category_label' => __("api.wallet.categories.{$transaction->category->value}", [], $locale),
            'method' => $method,
            'method_label' => $method === null ? null : __("api.payment.methods.{$method}", [], $locale),
            'created_at' => $at?->toIso8601String(),
            'date' => $at?->format('Y-m-d'),
            'time' => $at?->format('h:i A'),
        ];
    }

    /**
     * The receipt for one row: the list row plus the pool's balance either
     * side of it and the payment it belongs to.
     *
     * @param  int  $balanceAfter  WalletService::balanceAfter() for this row
     * @return array<string, mixed>
     */
    public function detail(WalletTransaction $transaction, ?User $viewer, int $balanceAfter, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $payment = $transaction->payment();

        return $this->item($transaction, $viewer, $locale) + [
            'reference' => $transaction->reference,
            // The pool the row moved: its category, as `category_label` names it.
            'balance' => [
                'before' => $this->prices->total($balanceAfter - $transaction->amount, $viewer, $locale),
                'after' => $this->prices->total($balanceAfter, $viewer, $locale),
            ],
            'expires_at' => $this->time->toLocal($transaction->expires_at)?->toIso8601String(),
            'payment' => $payment === null ? null : [
                'id' => $payment->id,
                'reference' => $payment->reference,
                'status' => $payment->status->value,
                'purpose' => $payment->purpose->value,
                // The bank's reference — what a member quotes when disputing.
                'rrn' => $payment->rrn,
                'payable' => $payment->payable_type === null
                    ? null
                    : ['type' => $payment->payable_type, 'id' => $payment->payable_id],
            ],
        ];
    }

    /**
     * What happened: what the payment bought for a spend, `top_up` or
     * `payment_received` for money coming in, `refund`; for a row no
     * payment explains, its source.
     */
    private function kind(WalletTransaction $transaction, ?Payment $payment): string
    {
        if ($transaction->refundPayment !== null) {
            return 'refund';
        }

        if ($transaction->debitPayment !== null) {
            return $transaction->debitPayment->purpose->value;
        }

        if ($payment !== null) {
            return $payment->purpose === PaymentPurpose::WalletTopUp ? 'top_up' : 'payment_received';
        }

        return match ($transaction->source) {
            WalletTransactionSource::TopUp => $transaction->amount < 0 ? 'spend' : 'top_up',
            // A refund that takes points away is a card payment clawed back
            // (ReverseWalletCreditOnPaymentReversed).
            WalletTransactionSource::Refund => $transaction->amount < 0 ? 'reversal' : 'refund',
            WalletTransactionSource::SubscriptionGrant => 'subscription_grant',
            WalletTransactionSource::Gift => 'gift',
            WalletTransactionSource::CompanyAdminAllocation => 'company_allocation',
        };
    }

    private function title(WalletTransaction $transaction, ?Payment $payment, string $kind, string $locale): string
    {
        $label = __("api.wallet.kinds.{$kind}", [], $locale);

        if ($kind === 'spend') {
            return $transaction->description ?: $label;
        }

        $subject = $payment === null ? null : $this->subject($payment, $locale);

        return $subject === null ? $label : "{$label} — {$subject}";
    }

    /**
     * What the payment was for, by name: the space, the event, the plan.
     */
    private function subject(Payment $payment, string $locale): ?string
    {
        if ($payment->purpose === PaymentPurpose::PlanPurchase) {
            return $payment->plan?->translate('name', $locale);
        }

        $payable = $payment->payable;

        return match (true) {
            $payable instanceof Booking, $payable instanceof WalkinSession => $payable->space?->translate('name', $locale),
            $payable instanceof EventAttendee => $payable->event?->translate('title', $locale),
            default => null,
        };
    }

    /**
     * How the row moved: points spent from the wallet, or the way money
     * arrived for a credit. Null for points that came from no payment (a
     * grant, a gift, a refund) and for a clawback.
     */
    private function method(WalletTransaction $transaction, string $kind): ?string
    {
        return match (true) {
            $kind === 'reversal' => null,
            $transaction->amount < 0 => WalletMethod::KEY,
            $transaction->payment_method !== null => $transaction->payment_method->value,
            $transaction->creditPayment !== null => $transaction->creditPayment->method,
            default => null,
        };
    }

    /**
     * @return array{amount: string, currency: string, label: string}|null
     */
    private function paid(WalletTransaction $transaction, string $locale): ?array
    {
        if ($transaction->payment_amount === null || $transaction->payment_currency === null) {
            return null;
        }

        $amount = (string) $transaction->payment_amount;

        return [
            'amount' => $amount,
            'currency' => $transaction->payment_currency,
            'label' => $this->prices->currencyLabel($amount, $transaction->payment_currency, $locale),
        ];
    }
}
