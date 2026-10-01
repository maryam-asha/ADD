<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\SpaceCoworkingWindow;
use App\Domain\Booking\ValueObjects\PoolDemand;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\Methods\WalletMethod;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Space;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Models\PlanDiscountRedemption;
use App\Domain\Membership\Services\MembershipResolver;
use App\Domain\Membership\Services\PlanDiscountService;
use App\Domain\Membership\Services\WalletService;
use Illuminate\Support\Facades\DB;

/**
 * Used by both the member's own extend request and reception acting on the
 * member's behalf — one service, two thin routes (see the design doc).
 * Locks the Space row (same pattern as BookingCreationService) so this
 * serializes correctly against both a concurrent new booking and a
 * concurrent second extension attempt on the same booking.
 */
class BookingExtensionService
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly AmountCalculator $amounts,
        private readonly WalletService $wallets,
        private readonly PaymentService $payments,
        private readonly MembershipResolver $membershipResolver,
        private readonly PlanDiscountService $discounts,
    ) {}

    public function extend(Booking $booking, int $additionalMinutes): void
    {
        DB::transaction(function () use ($booking, $additionalMinutes) {
            $lockedBooking = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedBooking->checked_in_at === null || $lockedBooking->checked_out_at !== null) {
                throw new ReceptionActionException('api.reception.not_checked_in');
            }

            $space = Space::query()->whereKey($lockedBooking->space_id)->with('building')->firstOrFail();
            $usesPool = $this->availability->usesBranchPool($space);

            if ($usesPool) {
                // Booking, then branch, then space: the order every path that
                // touches the co-working pool takes
                // (docs/decisions/coworking-seat-pool.md).
                Branch::query()->whereKey($space->building->branch_id)->lockForUpdate()->first();
            }

            $space = Space::query()->whereKey($lockedBooking->space_id)->lockForUpdate()->firstOrFail();

            $rules = $this->availability->rulesFor($space);

            if (! $this->availability->isValidDurationInMinutes($additionalMinutes, $rules)) {
                throw new ReceptionActionException('api.booking.invalid_extension_duration');
            }

            $previousEnd = $lockedBooking->end_at->copy();
            $newEndAt = $lockedBooking->end_at->copy()->addMinutes($additionalMinutes);

            if ($usesPool) {
                // A co_space's seat for the added stretch comes from its
                // branch's pool, with the booking counted on its member's
                // subscription as it is everywhere else.
                $timeline = $this->availability->poolTimeline(
                    $space,
                    $previousEnd,
                    $newEndAt,
                    PoolDemand::presence($previousEnd, $newEndAt, $lockedBooking->user, ignoreBookingId: $lockedBooking->id),
                );

                if ($this->availability->exceedsPool($timeline, $previousEnd, $newEndAt)) {
                    throw new ReceptionActionException('api.booking.extension_conflict', 422, [
                        'latest_end_at' => $timeline->firstShortfall()?->toIso8601String(),
                    ]);
                }
            } else {
                // The interval being added is what must be free — the
                // booking's own rows are excluded by ignoreBookingId rather
                // than by a separate query shape, so this is the same overlap
                // predicate the calendar and BookingCreationService use.
                $existing = $this->availability->blockingBookings($space, $previousEnd, $newEndAt, $rules);

                $conflicts = $existing
                    ->reject(fn (Booking $row) => $row->getKey() === $lockedBooking->getKey())
                    ->filter(fn (Booking $row) => $row->start_at < $newEndAt && $row->end_at > $previousEnd)
                    ->map(fn (Booking $row) => $row->start_at);

                // A room lent to co-working from some point on stops there too.
                $lent = $this->availability->blockingCoworkingWindows($space, $previousEnd, $newEndAt)
                    ->map(fn (SpaceCoworkingWindow $window) => $window->starts_at->greaterThan($previousEnd) ? $window->starts_at : $previousEnd);

                $stops = $conflicts->concat($lent)->sort()->values();

                if ($stops->isNotEmpty()) {
                    throw new ReceptionActionException('api.booking.extension_conflict', 422, [
                        'latest_end_at' => $stops->first()->toIso8601String(),
                    ]);
                }
            }

            $oldPoints = $this->amounts->forRange($space, $lockedBooking->start_at, $lockedBooking->end_at);
            $newPoints = $this->amounts->forRange($space, $lockedBooking->start_at, $newEndAt);

            // A discounted booking keeps being discounted as it grows — the
            // additional time is charged at the same net rate as the rest
            // of the booking, not the raw undiscounted difference
            // (docs/decisions/plan-discounts-design.md).
            $redemption = PlanDiscountRedemption::where('booking_id', $lockedBooking->id)->first();
            $membership = $redemption !== null ? $this->membershipResolver->activeFor($lockedBooking->user) : null;
            $discountedOld = null;
            $discountedNew = null;

            if ($redemption !== null && $membership !== null) {
                $discount = $redemption->discount;
                $discountedOld = $this->discounts->apply($oldPoints, $discount);
                $discountedNew = $this->discounts->apply($newPoints, $discount);
                $differencePoints = $discountedNew - $discountedOld;
            } else {
                $differencePoints = $newPoints - $oldPoints;
            }

            // The booking's whole price, as creation records it, grows by
            // exactly what the extension costs — so what it still owes (its
            // price less what was paid for it, Booking::pointsPaid) grows by
            // that and nothing more.
            $newPrice = ($lockedBooking->price_points ?? $discountedOld ?? $oldPoints) + $differencePoints;

            $paymentState = $lockedBooking->payment_state;

            if ($differencePoints > 0 && $paymentState === PaymentState::Paid && ! $this->chargeExtension($lockedBooking, $differencePoints)) {
                // Owes the extension, and only the extension: what was paid
                // stays paid, and reception, pay-now and check-out charge
                // the difference.
                $paymentState = PaymentState::Unpaid;
            }

            if ($redemption !== null && $discountedNew !== null) {
                $redemption->update(['points_discounted' => $newPoints - $discountedNew]);
            }

            $lockedBooking->forceFill([
                'end_at' => $newEndAt,
                'payment_state' => $paymentState,
                'price_points' => $newPrice,
            ])->save();
        });
    }

    /**
     * A booking paid from the wallet pays its extension from the member's
     * own wallet, when that covers the difference — through a payment row,
     * so cancelling or rejecting the booking later refunds these points
     * with the rest. Any other paid booking (a card, the desk) is not
     * charged behind the member's back: it is left owing the difference.
     */
    private function chargeExtension(Booking $booking, int $differencePoints): bool
    {
        if ($booking->payment_source !== PaymentSource::Wallet) {
            return false;
        }

        $personalOption = collect($this->wallets->spendOptions($booking->user, WalletTransactionCategory::SpaceSpecific))
            ->firstWhere('owner_type', OwnerType::User->value);

        if ($personalOption === null || $personalOption['usable_balance'] < $differencePoints) {
            return false;
        }

        $this->payments->start(PaymentPurpose::BookingExtension, $booking->user, WalletMethod::KEY, [
            'payable' => $booking,
            'points' => $differencePoints,
            'wallet_owner_type' => OwnerType::User,
            'wallet_owner_id' => $booking->user_id,
        ]);

        return true;
    }
}
