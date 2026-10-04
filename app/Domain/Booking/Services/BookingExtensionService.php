<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\Methods\WalletMethod;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Space;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Services\WalletService;
use Illuminate\Support\Facades\DB;

/**
 * Used by both the member's own extend request and reception acting on the
 * member's behalf — one service, two thin routes (see the design doc).
 * Locks the Space row (same pattern as BookingCreationService) so this
 * serializes correctly against both a concurrent new booking and a
 * concurrent second extension attempt on the same booking.
 *
 * Whether, how far and at what price are BookingExtensionPlanner's, the
 * same definition the extension options are offered from.
 */
class BookingExtensionService
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly BookingExtensionPlanner $planner,
        private readonly WalletService $wallets,
        private readonly PaymentService $payments,
    ) {}

    /**
     * Returns where the member sits from now on when the extension moved
     * them — their lent room went back to being a room
     * (docs/decisions/coworking-seat-pool.md, "Seating placement") — and
     * null when they stay where they are.
     */
    public function extend(Booking $booking, int $additionalMinutes): ?Space
    {
        return DB::transaction(function () use ($booking, $additionalMinutes) {
            $lockedBooking = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            $space = Space::query()->whereKey($lockedBooking->space_id)->with('building')->firstOrFail();
            $usesPool = $this->availability->usesBranchPool($space);

            if ($usesPool) {
                // Booking, then branch, then space: the order every path that
                // touches the co-working pool takes
                // (docs/decisions/coworking-seat-pool.md).
                Branch::query()->whereKey($space->building->branch_id)->lockForUpdate()->first();
            }

            $space = Space::query()->whereKey($lockedBooking->space_id)->lockForUpdate()->firstOrFail();

            // The same rules the options endpoint offers from, re-checked on
            // the locked rows (docs/decisions/booking-outcomes-and-extension.md).
            if (($reason = $this->planner->blockReason($lockedBooking, $space)) !== null) {
                throw new ReceptionActionException($reason->messageKey());
            }

            $rules = $this->availability->rulesFor($space);

            if (! $this->availability->isValidDurationInMinutes($additionalMinutes, $rules)) {
                throw new ReceptionActionException('api.booking.invalid_extension_duration');
            }

            $newEndAt = $lockedBooking->end_at->copy()->addMinutes($additionalMinutes);

            // As far as the next booking (less its buffer), a co-working loan,
            // the seat pool or the branch's closing allows — whichever is first.
            [$latestEnd, $stop] = $this->planner->latestEnd($lockedBooking, $space, $rules);

            if ($newEndAt->greaterThan($latestEnd)) {
                throw new ReceptionActionException($stop->messageKey(), 422, [
                    'latest_end_at' => $latestEnd->toIso8601String(),
                ]);
            }

            // Where the added time is sat: the member's own lent room while
            // it still has them, else the base seats, else another lent room.
            $seatSpaceId = $usesPool
                ? $this->availability->seatFor($space, $lockedBooking->end_at, $newEndAt, $lockedBooking->user, $lockedBooking->id, $lockedBooking->seat_space_id)?->id
                : null;

            // A discounted booking keeps being discounted as it grows — the
            // additional time is charged at the same net rate as the rest
            // of the booking, not the raw undiscounted difference
            // (docs/decisions/plan-discounts-design.md).
            $redemption = $this->planner->activeRedemption($lockedBooking);
            $price = $this->planner->price($lockedBooking, $space, $newEndAt, $redemption);
            $differencePoints = $price['difference'];

            $paymentState = $lockedBooking->payment_state;

            if ($lockedBooking->payment_state === PaymentState::Paid && $lockedBooking->payment_source === PaymentSource::Wallet) {
                $personalOption = collect($this->wallets->spendOptions($lockedBooking->user, WalletTransactionCategory::SpaceSpecific))
                    ->firstWhere('owner_type', OwnerType::User->value);

                if ($personalOption !== null && $personalOption['usable_balance'] >= $differencePoints) {
                    // Through a payment row, so cancelling or rejecting the
                    // booking later refunds these points with the rest.
                    if ($differencePoints > 0) {
                        $this->payments->start(PaymentPurpose::BookingExtension, $lockedBooking->user, WalletMethod::KEY, [
                            'payable' => $lockedBooking,
                            'points' => $differencePoints,
                            'wallet_owner_type' => OwnerType::User,
                            'wallet_owner_id' => $lockedBooking->user_id,
                        ]);
                    }
                } else {
                    $paymentState = PaymentState::Unpaid;
                }
            } else {
                $paymentState = PaymentState::Unpaid;
            }

            if ($redemption !== null) {
                $redemption->update(['points_discounted' => $price['undiscounted_total'] - $price['total']]);
            }

            $moved = $seatSpaceId !== $lockedBooking->seat_space_id;

            $lockedBooking->forceFill([
                'end_at' => $newEndAt,
                'seat_space_id' => $seatSpaceId,
                'payment_state' => $paymentState,
                // The booking's whole price in points, as creation records it.
                'price_points' => $price['total'],
            ])->save();

            return $moved ? $lockedBooking->fresh()->seatingSpace() : null;
        });
    }
}
