<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Both actions lock a fresh copy of the Booking row before deciding — the
 * same pattern BookingCancellationService::cancel() already uses — and act
 * only on that locked copy, never the caller's in-memory $booking.
 */
class BookingApprovalService
{
    public function __construct(
        private readonly BookingPlanBenefits $planBenefits,
        private readonly BookingRefunder $refunder,
        private readonly BookingNotifier $notifier,
    ) {}

    public function approve(Booking $booking, User $operator): void
    {
        DB::transaction(function () use ($booking, $operator) {
            $locked = $this->lockPending($booking);

            $locked->forceFill([
                'status' => BookingStatus::Confirmed,
                'approved_by' => $operator->id,
                'approved_at' => now(),
            ])->save();

            $this->notifier->approved($locked);
        });
    }

    public function reject(Booking $booking, User $operator, string $reason): void
    {
        if (trim($reason) === '') {
            throw new ReceptionActionException('api.booking.rejection_reason_required');
        }

        DB::transaction(function () use ($booking, $operator, $reason) {
            $locked = $this->lockPending($booking);

            $locked->forceFill([
                'status' => BookingStatus::Rejected,
                'rejection_reason' => $reason,
                'approved_by' => $operator->id,
                'approved_at' => now(),
            ])->save();

            $refunded = $this->refunder->quote($locked)['points'];
            $this->refunder->refund($locked, 'Booking rejection refund');

            // The usage this booking consumed is returned once it no
            // longer holds a charge (docs/decisions/plan-discounts-design.md).
            $this->planBenefits->voidFor($locked);

            $this->notifier->rejected($locked, $refunded);
        });
    }

    /**
     * A request still undecided when it starts can no longer be used — check-in
     * needs it confirmed — so it is closed exactly like a rejection, refund
     * included, with no operator and no reason: Booking::lapsedUnreviewed()
     * tells the two apart, and the member sees a translated explanation
     * rather than a stored string in the server's language.
     *
     * Returns false when the booking was decided in the meantime; the
     * scheduled command treats that as nothing to do, not an error.
     */
    public function lapse(Booking $booking): bool
    {
        return DB::transaction(function () use ($booking) {
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== BookingStatus::Pending) {
                return false;
            }

            $locked->forceFill([
                'status' => BookingStatus::Rejected,
                'rejection_reason' => null,
                'approved_by' => null,
                'approved_at' => now(),
            ])->save();

            $refunded = $this->refunder->quote($locked)['points'];
            $this->refunder->refund($locked, 'Booking lapsed unreviewed refund');
            $this->planBenefits->voidFor($locked);
            $this->notifier->lapsed($locked, $refunded);

            return true;
        });
    }

    private function lockPending(Booking $booking): Booking
    {
        $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->status !== BookingStatus::Pending) {
            throw new ReceptionActionException('api.booking.not_pending', 409);
        }

        return $locked;
    }
}
