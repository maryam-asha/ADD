<?php

namespace App\Domain\Booking\Services;

use App\Domain\Access\Services\PasscodeIssuanceService;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Events\BookingExtended;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\ValueObjects\ExtensionOutcome;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Methods\WalletMethod;
use App\Domain\Finance\Payments\PaymentMethodRegistry;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Finance\Payments\PaymentStart;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Space;
use App\Domain\Pricing\Services\QuoteRecorder;
use App\Services\Realtime\RealtimeBroadcaster;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Used by both the member's own extend request and reception acting on the
 * member's behalf — one service, two thin routes (see the design doc).
 * Locks the Space row (same pattern as BookingCreationService) so this
 * serializes correctly against both a concurrent new booking and a
 * concurrent second extension attempt on the same booking.
 *
 * Whether, how far and at what price are BookingExtensionPlanner's, the
 * same definition the extension options are offered from.
 *
 * What the added time costs is paid by the method chosen for it, never one
 * assumed from how the booking itself was paid:
 *
 * - `wallet`: spent with the extension, from the wallet named (or the only
 *   one the member may spend from); short, nothing is extended.
 * - a card: opened once the extension is saved (openCardPayment()); the
 *   booking owes the difference until the bank pays it.
 * - `cash`: owed, collected at the desk when the booking closes
 *   (ClosedBookingSettlement tells reception).
 * - none (reception only): owed, paid at the desk, from the app or at
 *   check-out, as any booking still owing.
 */
class BookingExtensionService
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly BookingExtensionPlanner $planner,
        private readonly PaymentService $payments,
        private readonly PaymentMethodRegistry $methods,
        private readonly QuoteRecorder $recorder,
        private readonly RealtimeBroadcaster $realtime,
        private readonly BookingNotifier $notifier,
        private readonly PasscodeIssuanceService $passcodes,
    ) {}

    /**
     * `$methodRequired`: an extension that costs anything is refused without
     * a method (the member's own request); reception may leave it owed.
     *
     * The outcome says where the member sits from now on when the extension
     * moved them — their lent room went back to being a room
     * (docs/decisions/coworking-seat-pool.md, "Seating placement") — null
     * when they stay where they are.
     *
     * @param  array{wallet_owner_type?: string|null, wallet_owner_id?: int|null}  $walletContext
     */
    public function extend(
        Booking $booking,
        int $additionalMinutes,
        ?string $method = null,
        array $walletContext = [],
        bool $methodRequired = false,
    ): ExtensionOutcome {
        return DB::transaction(function () use ($booking, $additionalMinutes, $method, $walletContext, $methodRequired) {
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

            // The booking's whole price, as creation records it, grows by
            // exactly what the extension costs — so what it still owes (its
            // price less what was paid for it, Booking::pointsPaid) grows by
            // that and nothing more.
            $newPrice = ($lockedBooking->price_points ?? $price['total'] - $differencePoints) + $differencePoints;

            $paymentState = $lockedBooking->payment_state;
            $chosenMethod = $lockedBooking->chosen_payment_method;
            $payFromWallet = false;
            $gateway = null;

            if ($differencePoints > 0) {
                if ($method === null && $methodRequired) {
                    throw new ReceptionActionException('api.booking.payment_method_required');
                }

                if ($method === WalletMethod::KEY) {
                    // Spent below, once the booking carries its new price.
                    $payFromWallet = true;
                } else {
                    // Owes the extension, and only the extension: what was
                    // paid stays paid. A card opens once this is saved; cash
                    // and no method at all leave it to the desk, pay-now and
                    // check-out — by the method chosen here, when there is one.
                    if ($paymentState === PaymentState::Paid) {
                        $paymentState = PaymentState::Unpaid;
                    }

                    if ($method !== null) {
                        $chosenMethod = $method;
                    }

                    if ($method !== null && in_array($method, $this->methods->gatewayKeys(), true)) {
                        $gateway = $method;
                    }
                }
            }

            if ($redemption !== null) {
                $redemption->update(['points_discounted' => $price['undiscounted_total'] - $price['total']]);
            }

            // The ledger's row for the offer or plan discount grows with it.
            $this->recorder->recordExtension($lockedBooking, $price, $redemption);

            $moved = $seatSpaceId !== $lockedBooking->seat_space_id;

            $lockedBooking->forceFill([
                'end_at' => $newEndAt,
                'seat_space_id' => $seatSpaceId,
                'payment_state' => $paymentState,
                'chosen_payment_method' => $chosenMethod,
                'price_points' => $newPrice,
                // The list price grows with it, so the breakdown still adds up.
                'list_price_points' => ($lockedBooking->list_price_points ?? $price['undiscounted_total'] - $price['list_difference']) + $price['list_difference'],
            ])->save();

            // From the wallet the member named, inside this transaction: a
            // wallet that falls short refuses the whole extension, so nothing
            // is extended that was not paid the way they chose.
            $walletPayment = $payFromWallet
                ? $this->payments->start(PaymentPurpose::BookingExtension, $lockedBooking->user, WalletMethod::KEY, [
                    'payable' => $lockedBooking,
                    'points' => $differencePoints,
                    'wallet_owner_type' => $walletContext['wallet_owner_type'] ?? null,
                    'wallet_owner_id' => $walletContext['wallet_owner_id'] ?? null,
                ])['payment']
                : null;

            $this->realtime->send(new BookingExtended($lockedBooking));

            // Told once it is saved, read again then, so the payment line
            // says what actually happened to the added time.
            DB::afterCommit(function () use ($lockedBooking, $differencePoints) {
                $fresh = Booking::query()->with(['space', 'user', 'payments'])->find($lockedBooking->id);

                if ($fresh !== null) {
                    // The door runs to the new end too (never throws).
                    $this->passcodes->extendForBooking($fresh);
                    $this->notifier->extended($fresh, $differencePoints);
                }
            });

            return new ExtensionOutcome(
                $moved ? $lockedBooking->fresh()->seatingSpace() : null,
                $differencePoints,
                $walletPayment,
                $gateway,
            );
        });
    }

    /**
     * The card payment for an extension the member chose to pay by card,
     * opened once the extension is saved — never inside its transaction: a
     * gateway's row must outlive a call that fails, as PaymentService opens
     * every card payment. The extension stands whatever the bank says; until
     * it pays, the booking owes the difference
     * (BookingExtensionFulfiller marks it paid again).
     *
     * @return array{payment: Payment, start: PaymentStart}
     */
    public function openCardPayment(Booking $booking, ExtensionOutcome $outcome): array
    {
        if ($outcome->gateway === null) {
            throw new LogicException('Only an extension the member chose to pay by card opens a card payment.');
        }

        $locale = app()->getLocale();

        return $this->payments->start(PaymentPurpose::BookingExtension, $booking->user, $outcome->gateway, array_filter([
            'payable' => $booking,
            'points' => $outcome->differencePoints,
            // The gateway page speaks the member's language.
            'lang' => in_array($locale, ['ar', 'en'], true) ? $locale : null,
        ], fn ($value) => $value !== null));
    }
}
