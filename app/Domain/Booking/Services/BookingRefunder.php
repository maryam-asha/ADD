<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\PlanDiscountRedemption;
use App\Domain\Membership\Services\WalletService;

/**
 * Gives a cancelled or rejected booking's points back — the one refund rule
 * BookingCancellationService and BookingApprovalService share.
 *
 * A booking paid through a payment row is refunded from its rows: every
 * accepted payment that spent points on it (the booking and any extension),
 * each to the wallet that paid it. A booking paid before payment rows
 * existed has none, and keeps the refund it always had: its price
 * recomputed, less any plan discount, to the member's own wallet.
 *
 * Runs inside the caller's transaction, on the booking it has locked.
 */
class BookingRefunder
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly WalletService $wallets,
        private readonly AmountCalculator $amounts,
    ) {}

    public function refund(Booking $booking, string $description): void
    {
        $rows = Payment::query()->forPayable($booking)->get();

        if ($rows->isNotEmpty()) {
            $rows
                ->filter(fn (Payment $p) => $p->status === PaymentStatus::Accepted && $p->debit_transaction_id !== null && ! $p->isRefunded())
                ->each(fn (Payment $p) => $this->payments->refund($p, $description));

            return;
        }

        $this->refundWithoutPaymentRows($booking, $description);
    }

    private function refundWithoutPaymentRows(Booking $booking, string $description): void
    {
        if ($booking->payment_source !== PaymentSource::Wallet || $booking->payment_state !== PaymentState::Paid) {
            return;
        }

        $points = $this->amounts->forRange($booking->space, $booking->start_at, $booking->end_at);

        // A discounted booking was only ever charged the net amount, so it is
        // only that net amount that gets refunded — not the raw rate.
        $redemption = PlanDiscountRedemption::where('booking_id', $booking->id)->first();
        $refundPoints = $redemption !== null ? $points - $redemption->points_discounted : $points;

        $wallet = $this->wallets->walletFor(OwnerType::User, $booking->user_id);
        $this->wallets->creditGeneral($wallet, $refundPoints, WalletTransactionSource::Refund, $description);
    }
}
