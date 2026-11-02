<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Settings\Services\SettingService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class BookingCancellationService
{
    public function __construct(
        private readonly BookingPlanBenefits $planBenefits,
        private readonly SettingService $settings,
        private readonly BookingRefunder $refunder,
        private readonly CoworkingBookingPolicy $policy,
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
        if (! $booking->status->isOpen()) {
            return false;
        }

        if ($booking->checked_in_at !== null) {
            return false;
        }

        // Nothing was paid for a hold, so the window does not apply.
        if ($booking->status === BookingStatus::AwaitingPayment) {
            return true;
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

    /**
     * @return int the points given back
     */
    public function cancel(Booking $booking): int
    {
        return DB::transaction(function () use ($booking) {
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === BookingStatus::Cancelled) {
                throw new ReceptionActionException('api.reception.already_cancelled', 409);
            }

            // Rejected, completed or a no-show is already final: cancelling
            // it would overwrite its outcome, and a rejected one would be
            // refunded a second time.
            if (! $locked->status->isOpen()) {
                throw new ReceptionActionException('api.booking.not_cancellable', 409);
            }

            if ($locked->checked_in_at !== null) {
                throw new ReceptionActionException('api.reception.already_checked_in', 409);
            }

            $windowMinutes = $this->windowMinutesFor($locked);

            if ($locked->status !== BookingStatus::AwaitingPayment
                && now()->gt($locked->start_at->copy()->subMinutes($windowMinutes))) {
                throw new ReceptionActionException('api.reception.cancellation_window_passed');
            }

            return $this->markCancelled($locked);
        });
    }

    /**
     * A hold let go by the system — its deadline passed, or the member
     * superseded it with another time. No cancellation window applies
     * (nothing was paid) and a hold already released is left alone.
     *
     * @return bool whether it was cancelled
     */
    public function releaseUnpaidHold(Booking $booking): bool
    {
        return DB::transaction(function () use ($booking) {
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== BookingStatus::AwaitingPayment) {
                return false;
            }

            $this->markCancelled($locked);

            return true;
        });
    }

    /**
     * An unpaid coworking booking its member has not come to, let go by the
     * system once its share of time has passed (CoworkingBookingPolicy) so
     * the seat is free for someone else. Nothing was paid, so no
     * cancellation window applies. Re-checked under the row lock: one paid,
     * checked in, or waited for by the member's own early walk-in since the
     * caller read it stays.
     *
     * @return bool whether it was cancelled
     */
    public function releaseUnpaidNoShow(Booking $booking): bool
    {
        return DB::transaction(function () use ($booking) {
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== BookingStatus::Confirmed
                || $locked->payment_state !== PaymentState::Unpaid
                || $locked->checked_in_at !== null
                || $locked->space->space_type !== SpaceType::CoSpace
                || ! $locked->end_at->isFuture()
                || $this->policy->releaseAt($locked)->isFuture()
                || $locked->pointsPaid() > 0
                || WalkinSession::query()->open()->where('handover_booking_id', $locked->id)->exists()) {
                return false;
            }

            $this->markCancelled($locked);

            return true;
        });
    }

    /**
     * @return int the points given back
     */
    private function markCancelled(Booking $locked): int
    {
        $locked->forceFill([
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => now(),
        ])->save();

        $refunded = $this->refunder->quote($locked)['points'];
        $this->refunder->refund($locked, 'Booking cancellation refund');

        // The usage this booking consumed is returned once it no
        // longer holds a charge (docs/decisions/plan-discounts-design.md),
        // with the plan hours it took (BookingPlanBenefits).
        $this->planBenefits->voidFor($locked);

        return $refunded;
    }
}
