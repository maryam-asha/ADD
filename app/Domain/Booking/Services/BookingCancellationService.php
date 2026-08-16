<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Settings\Services\SettingService;

class BookingCancellationService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly SettingService $settings,
        private readonly AmountCalculator $amounts,
    ) {}

    /**
     * A cancelled-but-never-checked-in booking was never counted toward
     * WalkInCapacityService's occupancy sum (that only counts
     * checked_in_at IS NOT NULL AND checked_out_at IS NULL rows) — setting
     * status = Cancelled doesn't change any capacity query result; "capacity
     * released" is true by construction, nothing further to do for it.
     *
     * The refund amount (when payment_source = wallet) uses the booking's
     * PLANNED window (start_at to end_at), not amount_owed — amount_owed is
     * only ever set at checkout (SessionClosureService), and cancellation
     * can only happen before check-in, so it's always null here. A booking
     * marked paid via wallet before this phase's out-of-scope creation flow
     * would have been charged for the planned window; that's what gets
     * refunded.
     */
    public function cancel(Booking $booking): void
    {
        if ($booking->status === BookingStatus::Cancelled) {
            throw new ReceptionActionException('api.reception.already_cancelled', 409);
        }

        if ($booking->checked_in_at !== null) {
            throw new ReceptionActionException('api.reception.already_checked_in', 409);
        }

        $windowMinutes = $booking->space->cancellation_window_minutes
            ?? $this->settings->get('booking.cancellation_window_minutes', 60);

        if (now()->gt($booking->start_at->copy()->subMinutes($windowMinutes))) {
            throw new ReceptionActionException('api.reception.cancellation_window_passed');
        }

        $booking->forceFill([
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => now(),
        ])->save();

        if ($booking->payment_source === PaymentSource::Wallet && $booking->payment_state === PaymentState::Paid) {
            [$refundAmount] = $this->amounts->forRange($booking->space, $booking->start_at, $booking->end_at);

            $wallet = $this->wallets->walletFor(OwnerType::User, $booking->user_id);
            $this->wallets->creditGeneral($wallet, $refundAmount, WalletTransactionSource::Refund, 'Booking cancellation refund');
        }
    }
}
