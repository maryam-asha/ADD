<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\ValueObjects\PoolDemand;
use App\Domain\Booking\ValueObjects\SpaceBookingRules;
use App\Domain\Finance\Services\PointConversionService;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\NotificationLog;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Services\CouponEligibility;
use App\Domain\Pricing\Services\CouponLedger;
use App\Domain\Pricing\Services\PriceQuoteService;
use App\Domain\Pricing\Services\QuoteRecorder;
use App\Domain\Settings\Services\SettingService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Member-facing booking creation — the piece Reception Operations
 * deliberately deferred. Validation order matches the phase brief exactly:
 * business hours, then granularity, then duration (none of these depend on
 * shared state, so none need a lock); then, inside one locked transaction
 * on the Space row, overlap + live occupancy, then buffer; then payment;
 * then the requires_approval branch. Task 4 adds payment routing and Task 5
 * adds the requires_approval branch to this same create() method.
 */
class BookingCreationService
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly PointConversionService $points,
        private readonly SettingService $settings,
        private readonly BookingPaymentRouter $payments,
        private readonly PriceQuoteService $quotes,
        private readonly QuoteRecorder $recorder,
        private readonly CouponEligibility $coupons,
        private readonly CouponLedger $couponLedger,
    ) {}

    public function create(
        Space $space,
        User $member,
        CarbonInterface $startAt,
        CarbonInterface $endAt,
        ?OwnerType $walletOwnerType = null,
        ?int $walletOwnerId = null,
        ?string $note = null,
        ?string $paymentMethod = null,
        ?string $couponCode = null,
    ): Booking {
        // Normalize to UTC: Eloquent's datetime cast stores the Carbon
        // value's current wall-clock as-is and reinterprets it as UTC on
        // read, so a non-UTC input (e.g. a Damascus-timezone request time)
        // would round-trip with a 3-hour drift — the same fix
        // SessionClosureService::finalizeClosure() applies to
        // checked_out_at for the identical reason.
        $startAt = $startAt->copy()->setTimezone('UTC');
        $endAt = $endAt->copy()->setTimezone('UTC');

        $rules = $this->availability->rulesFor($space);

        $this->assertInTheFuture($startAt);
        $this->assertWithinBookingWindow($startAt, $rules);
        $this->assertWithinBusinessHours($space, $startAt, $endAt, $rules);
        $this->assertValidGranularity($startAt, $rules);
        $this->assertValidDuration($startAt, $endAt, $rules);

        // An unknown code is refused before anything is locked.
        $coupon = $couponCode !== null ? $this->coupons->resolve($couponCode) : null;

        return DB::transaction(function () use ($space, $member, $startAt, $endAt, $walletOwnerType, $walletOwnerId, $rules, $note, $paymentMethod, $coupon) {
            // The coupon's row first — before the branch and the space — so
            // its limits are counted from a fresh snapshot (CouponLedger).
            $coupon = $coupon !== null ? $this->couponLedger->lockForRedemption($coupon) : null;

            if ($rules->usesBranchPool) {
                // Every decision about a branch's co-working seats — bookings,
                // walk-ins, subscriptions — is serialized on the branch row,
                // taken before the space's (docs/decisions/coworking-seat-pool.md).
                Branch::query()->whereKey($space->building->branch_id)->lockForUpdate()->first();
            }

            $locked = Space::query()->whereKey($space->id)->lockForUpdate()->firstOrFail();

            // Read off the locked row, so a space put into maintenance while
            // this request was in flight is refused rather than booked.
            $this->assertAcceptsBookings($locked);

            // Re-read inside the lock. Nothing the calendar computed is
            // reused here — the read path is advisory, and only these
            // freshly-read rows decide whether the slot is actually free.
            $existing = $this->availability->blockingBookings($locked, $startAt, $endAt, $rules);

            if ($rules->usesBranchPool) {
                $this->assertPoolHasRoom($locked, $member, $startAt, $endAt);
            } else {
                $this->assertSlotAvailable($existing, $startAt, $endAt, $rules);
                $this->assertNotLentToCoworking($locked, $startAt, $endAt);
                $this->assertOccupancyLeavesRoom($locked, $startAt, $endAt);
            }

            $this->assertBufferRespected($existing, $startAt, $endAt, $rules);

            // The list price, the better of the space's sale and the
            // member's plan discount (applied automatically, never an opt-in
            // — docs/decisions/plan-discounts-design.md), and the coupon on
            // top: PriceQuoteService, the one place a price is composed.
            $quote = $this->quotes->forBooking($locked, $startAt, $endAt, $member, $coupon);
            $finalPoints = $quote->finalPoints;

            // Decided before the booking is made, so a refusal (a wallet
            // choice, a strict wallet payment that falls short) makes none.
            $payingWallet = $this->payments->choose($member, $finalPoints, $paymentMethod, $walletOwnerType, $walletOwnerId);

            // `amount_owed`/`currency` are the booking's money-denominated
            // record of the charge and remain decimal USD — only the wallet
            // ledger became point-denominated. Converting the point charge
            // back here is the intended direction, not leftover scaffolding.
            $amount = $this->points->pointsToUsd($finalPoints);

            $status = $locked->requires_approval ? BookingStatus::Pending : BookingStatus::Confirmed;

            $booking = Booking::create([
                'space_id' => $locked->id,
                'seat_space_id' => $rules->usesBranchPool ? $this->availability->seatFor($locked, $startAt, $endAt, $member)?->id : null,
                'user_id' => $member->id,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'status' => $status,
                'required_approval' => $status === BookingStatus::Pending,
                'amount_owed' => $amount,
                'currency' => 'USD',
                ...$quote->attributes(),
                'payment_state' => PaymentState::Unpaid,
                'note' => $note,
            ]);

            if ($payingWallet !== null) {
                $this->payments->pay($booking, $member, $payingWallet);
            }

            // A plan discount or a coupon is used up by the booking it
            // priced, paid now or later (cash, card, pay-now, the desk):
            // `price_points` is the reduced price whenever it is paid, so the
            // limits count it either way. Cancelling or rejecting gives it back.
            $this->recorder->record($quote, $booking, $member, PricingPurpose::Booking);

            if ($status === BookingStatus::Pending) {
                $this->notifyOperationsOfPendingBooking($booking);
            }

            return $booking;
        });
    }

    /**
     * NotificationLog rows only — no real send channel exists anywhere in
     * this app yet (see the decision doc). channel is written as 'push'
     * (the closest fit among the table's three legacy values for an
     * eventual in-app/dashboard notification); status 'sent' means
     * "generated by this system," not "confirmed delivered."
     */
    private function notifyOperationsOfPendingBooking(Booking $booking): void
    {
        User::role(['operations', 'admin'])->get()->each(function (User $recipient) {
            NotificationLog::create([
                'user_id' => $recipient->id,
                'channel' => 'push',
                'template_key' => 'booking.pending_approval',
                'status' => 'sent',
            ]);
        });
    }

    // ------------------------------------------------------------------
    // Every rule below is decided by AvailabilityService — the one copy in
    // this codebase. These methods exist only to turn a `false` into the
    // message key and status this endpoint has always returned, in the
    // order it has always checked them. Putting the rule itself back here
    // would make the calendar and this method drift apart silently, which
    // is what tests/Guards/AvailabilityRulesAreCentralizedTest prevents.
    // ------------------------------------------------------------------

    /**
     * A booking cannot start in the past.
     *
     * Closes a real trap rather than a theoretical one: a past-dated
     * booking could be created and paid for, and BookingCancellationService
     * then refuses to cancel it because its cancellation window has long
     * gone — so it could never be undone.
     *
     * A booking starting this very minute is fine; only a start strictly
     * before now is refused. The `booking.allow_past_bookings` setting
     * exists so reception can later be given a back-dating override
     * without this method changing.
     */
    private function assertInTheFuture(CarbonInterface $start): void
    {
        if ((bool) $this->settings->get('booking.allow_past_bookings', false)) {
            return;
        }

        if ($start->lessThan(now())) {
            throw new ReceptionActionException('api.booking.start_in_the_past');
        }
    }

    /**
     * The booking window: at least `min_notice_minutes` of notice, and no
     * further out than `max_days_ahead`. Both default to off.
     */
    private function assertWithinBookingWindow(CarbonInterface $start, SpaceBookingRules $rules): void
    {
        if (! $this->availability->isFarEnoughAhead($start, $rules)) {
            throw new ReceptionActionException('api.booking.too_soon');
        }

        if (! $this->availability->isWithinAdvanceHorizon($start, $rules)) {
            throw new ReceptionActionException('api.booking.too_far_ahead');
        }
    }

    private function assertAcceptsBookings(Space $space): void
    {
        if (! $this->availability->acceptsBookings($space)) {
            throw new ReceptionActionException('api.booking.space_not_bookable');
        }
    }

    private function assertWithinBusinessHours(Space $space, CarbonInterface $start, CarbonInterface $end, SpaceBookingRules $rules): void
    {
        if (! $this->availability->isWithinBusinessHours($space, $start, $end, $rules)) {
            throw new ReceptionActionException('api.reception.outside_business_hours');
        }
    }

    private function assertValidGranularity(CarbonInterface $start, SpaceBookingRules $rules): void
    {
        if (! $this->availability->isValidGranularity($start, $rules)) {
            throw new ReceptionActionException('api.booking.invalid_start_time');
        }
    }

    private function assertValidDuration(CarbonInterface $start, CarbonInterface $end, SpaceBookingRules $rules): void
    {
        if (! $this->availability->isLongEnough($start, $end, $rules)) {
            throw new ReceptionActionException('api.booking.duration_too_short');
        }

        if (! $this->availability->matchesDurationGranularity($start, $end, $rules)) {
            throw new ReceptionActionException('api.booking.duration_invalid_granularity');
        }
    }

    /**
     * @param  Collection<int, Booking>  $existing
     */
    private function assertSlotAvailable(Collection $existing, CarbonInterface $start, CarbonInterface $end, SpaceBookingRules $rules): void
    {
        if ($this->availability->exceedsCeiling($existing, $start, $end, $rules)) {
            throw new ReceptionActionException('api.booking.slot_unavailable');
        }
    }

    /**
     * A co_space booking needs a seat in its branch's co-working pool at
     * every moment of the window, counted with everything already there —
     * subscriptions, bookings, and the walk-ins inside now — and with this
     * booking on its member's subscription when they hold one
     * (docs/decisions/coworking-seat-pool.md).
     */
    private function assertPoolHasRoom(Space $space, User $member, CarbonInterface $start, CarbonInterface $end): void
    {
        $timeline = $this->availability->poolTimeline($space, $start, $end, PoolDemand::presence($start, $end, $member));

        if ($this->availability->exceedsPool($timeline, $start, $end)) {
            throw new ReceptionActionException('api.booking.slot_unavailable');
        }
    }

    /** A room lent to co-working takes no room booking while it is lent. */
    private function assertNotLentToCoworking(Space $space, CarbonInterface $start, CarbonInterface $end): void
    {
        $windows = $this->availability->blockingCoworkingWindows($space, $start, $end);

        if ($this->availability->isInCoworkingMode($windows, $start, $end)) {
            throw new ReceptionActionException('api.booking.room_in_coworking_mode');
        }
    }

    /**
     * Present-moment physical-presence count, reusing
     * WalkInCapacityService::start()'s exact counting logic. A room's only:
     * a co_space's walk-ins are in its branch's pool.
     *
     * Narrowed to windows that actually contain now(). It answers "is there
     * room in this space at this moment", which is the right question for
     * someone arriving now and the wrong one for next Tuesday: a booking
     * for tomorrow afternoon used to be refused because the room happened
     * to be full as the request was made, and no amount of waiting would
     * fix it. That also made a forward-looking calendar impossible to get
     * right, since the read path cannot know the present occupancy of a
     * future slot. Future windows are governed by the overlap-versus-
     * ceiling check above instead, which is the reservation-based model
     * space_capacity_slots now records.
     *
     * A deliberate loosening, documented in
     * docs/decisions/booking-availability-and-calendar.md.
     */
    private function assertOccupancyLeavesRoom(Space $space, CarbonInterface $start, CarbonInterface $end): void
    {
        if ($space->capacity === null) {
            return;
        }

        $now = now();

        if ($start->greaterThan($now) || $end->lessThanOrEqualTo($now)) {
            return;
        }

        $occupied = Booking::query()
            ->where('space_id', $space->id)
            ->whereNotNull('checked_in_at')
            ->whereNull('checked_out_at')
            ->count();

        $occupied += WalkinSession::query()
            ->where('space_id', $space->id)
            ->whereNull('checked_out_at')
            ->count();

        if ($occupied >= $space->capacity) {
            throw new ReceptionActionException('api.reception.no_capacity');
        }
    }

    /**
     * @param  Collection<int, Booking>  $existing
     */
    private function assertBufferRespected(Collection $existing, CarbonInterface $start, CarbonInterface $end, SpaceBookingRules $rules): void
    {
        if ($this->availability->violatesBuffer($existing, $start, $end, $rules)) {
            throw new ReceptionActionException('api.booking.buffer_conflict');
        }
    }
}
