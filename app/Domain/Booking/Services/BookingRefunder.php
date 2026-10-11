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
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Illuminate\Support\Collection;

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
            $this->refundable($rows)->each(fn (Payment $p) => $this->payments->refund($p, $description));

            return;
        }

        $points = $this->pointsWithoutPaymentRows($booking);

        if ($points > 0) {
            $wallet = $this->wallets->walletFor(OwnerType::User, $booking->user_id);
            $this->wallets->creditGeneral($wallet, $points, WalletTransactionSource::Refund, $description);
        }
    }

    /**
     * What refund() would give back right now, and to which wallets, without
     * giving it — the booking detail's cancellation screen. Same rows and
     * same arithmetic as refund(), so what the screen promises is what comes
     * back.
     *
     * @return array{points: int, wallets: list<Wallet>}
     */
    public function quote(Booking $booking): array
    {
        $rows = Payment::query()->forPayable($booking)->with('wallet')->get();

        if ($rows->isNotEmpty()) {
            $refundable = $this->refundable($rows);

            return [
                'points' => (int) $refundable->sum('amount_points'),
                'wallets' => $refundable->pluck('wallet')->filter()->unique('id')->values()->all(),
            ];
        }

        $points = $this->pointsWithoutPaymentRows($booking);

        return [
            'points' => $points,
            'wallets' => $points > 0 ? [$this->wallets->walletFor(OwnerType::User, $booking->user_id)] : [],
        ];
    }

    /**
     * Every accepted payment that spent points on the booking and has not
     * been given back.
     *
     * @param  Collection<int, Payment>  $rows
     * @return Collection<int, Payment>
     */
    private function refundable(Collection $rows): Collection
    {
        return $rows->filter(fn (Payment $p) => $p->status === PaymentStatus::Accepted && $p->debit_transaction_id !== null && ! $p->isRefunded());
    }

    /** A booking paid before payment rows existed: its price, less any plan discount, if it was paid from the wallet. */
    private function pointsWithoutPaymentRows(Booking $booking): int
    {
        if ($booking->payment_source !== PaymentSource::Wallet || $booking->payment_state !== PaymentState::Paid) {
            return 0;
        }

        $points = $this->amounts->forRange($booking->space, $booking->start_at, $booking->end_at);

        // A discounted booking was only ever charged the net amount, so it is
        // only that net amount that gets refunded — not the raw rate.
        $redemption = PlanDiscountRedemption::where('booking_id', $booking->id)->first();

        return $redemption !== null ? $points - $redemption->points_discounted : $points;
    }
}
