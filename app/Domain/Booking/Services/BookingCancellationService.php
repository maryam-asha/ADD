<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\PlanDiscountRedemption;
use App\Domain\Membership\Services\PlanDiscountService;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Settings\Services\SettingService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class BookingCancellationService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly SettingService $settings,
        private readonly AmountCalculator $amounts,
        private readonly PlanDiscountService $discounts,
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
    /**
     * Whether cancel() would succeed, without attempting it.
     *
     * The member's booking detail response shows a Cancel button driven by
     * this exact predicate, so the button is never offered for an action
     * that would come straight back as a 422. Both callers share this one
     * method for the same reason the availability rules are centralised:
     * two copies of a rule drift.
     */
    public function canCancel(Booking $booking): bool
    {
        if (in_array($booking->status, [BookingStatus::Cancelled, BookingStatus::Rejected], true)) {
            return false;
        }

        if ($booking->checked_in_at !== null) {
            return false;
        }

        return ! now()->gt($booking->start_at->copy()->subMinutes($this->windowMinutesFor($booking)));
    }

    /**
     * The cancellation window in minutes: the space's own setting, else the
     * global default.
     */
    public function windowMinutesFor(Booking $booking): int
    {
        return (int) ($booking->space->cancellation_window_minutes
            ?? $this->settings->get('booking.cancellation_window_minutes', 60));
    }

    /**
     * The instant after which cancellation is refused.
     */
    public function deadlineFor(Booking $booking): CarbonInterface
    {
        return $booking->start_at->copy()->subMinutes($this->windowMinutesFor($booking));
    }

    public function cancel(Booking $booking): void
    {
        DB::transaction(function () use ($booking) {
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === BookingStatus::Cancelled) {
                throw new ReceptionActionException('api.reception.already_cancelled', 409);
            }

            if ($locked->checked_in_at !== null) {
                throw new ReceptionActionException('api.reception.already_checked_in', 409);
            }

            $windowMinutes = $this->windowMinutesFor($locked);

            if (now()->gt($locked->start_at->copy()->subMinutes($windowMinutes))) {
                throw new ReceptionActionException('api.reception.cancellation_window_passed');
            }

            $locked->forceFill([
                'status' => BookingStatus::Cancelled,
                'cancelled_at' => now(),
            ])->save();

            if ($locked->payment_source === PaymentSource::Wallet && $locked->payment_state === PaymentState::Paid) {
                $points = $this->amounts->forRange($locked->space, $locked->start_at, $locked->end_at);

                // A discounted booking was only ever charged the net
                // amount, so it is only that net amount that gets
                // refunded here — not the raw undiscounted rate.
                $redemption = PlanDiscountRedemption::where('booking_id', $locked->id)->first();
                $refundPoints = $redemption !== null ? $points - $redemption->points_discounted : $points;

                $wallet = $this->wallets->walletFor(OwnerType::User, $locked->user_id);
                $this->wallets->creditGeneral($wallet, $refundPoints, WalletTransactionSource::Refund, 'Booking cancellation refund');
            }

            // The usage this booking consumed is returned once it no
            // longer holds a charge (docs/decisions/plan-discounts-design.md).
            $this->discounts->voidFor($locked);
        });
    }
}
