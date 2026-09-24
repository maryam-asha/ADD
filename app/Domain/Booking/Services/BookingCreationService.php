<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Exceptions\WalletChoiceRequiredException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\ValueObjects\SpaceBookingRules;
use App\Domain\Finance\Services\PointConversionService;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\NotificationLog;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Services\MembershipResolver;
use App\Domain\Membership\Services\PlanDiscountService;
use App\Domain\Membership\Services\WalletService;
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
        private readonly CapacitySlotSynchronizer $capacitySlots,
        private readonly AmountCalculator $amounts,
        private readonly WalletService $wallets,
        private readonly PointConversionService $points,
        private readonly SettingService $settings,
        private readonly MembershipResolver $membershipResolver,
        private readonly PlanDiscountService $discounts,
    ) {}

    public function create(
        Space $space,
        User $member,
        CarbonInterface $startAt,
        CarbonInterface $endAt,
        ?OwnerType $walletOwnerType = null,
        ?int $walletOwnerId = null,
        ?string $note = null,
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
        $this->assertWithinBusinessHours($space, $startAt, $endAt, $rules);
        $this->assertValidGranularity($startAt, $rules);
        $this->assertValidDuration($startAt, $endAt, $rules);

        return DB::transaction(function () use ($space, $member, $startAt, $endAt, $walletOwnerType, $walletOwnerId, $rules, $note) {
            $locked = Space::query()->whereKey($space->id)->lockForUpdate()->firstOrFail();

            // Re-read inside the lock. Nothing the calendar computed is
            // reused here — the read path is advisory, and only these
            // freshly-read rows decide whether the slot is actually free.
            $existing = $this->availability->blockingBookings($locked, $startAt, $endAt, $rules);

            $this->assertSlotAvailable($existing, $startAt, $endAt, $rules);
            $this->assertOccupancyLeavesRoom($locked, $startAt, $endAt);
            $this->assertBufferRespected($existing, $startAt, $endAt, $rules);

            $points = $this->amounts->forRange($locked, $startAt, $endAt);

            // Applied automatically, never an opt-in the member triggers
            // (docs/decisions/plan-discounts-design.md) — the member's
            // active membership plan is consulted the same way as any
            // other benefit.
            $membership = $this->membershipResolver->activeFor($member);
            $discount = $membership !== null ? $this->discounts->resolveFor($membership, $locked) : null;
            $finalPoints = $discount !== null ? $this->discounts->apply($points, $discount) : $points;

            [$paymentState, $paymentSource] = $this->routePayment($locked, $member, $finalPoints, $walletOwnerType, $walletOwnerId);

            // `amount_owed`/`currency` are the booking's money-denominated
            // record of the charge and remain decimal USD — only the wallet
            // ledger became point-denominated. Converting the point charge
            // back here is the intended direction, not leftover scaffolding.
            $amount = $this->points->pointsToUsd($finalPoints);

            $status = $locked->requires_approval ? BookingStatus::Pending : BookingStatus::Confirmed;

            $booking = Booking::create([
                'space_id' => $locked->id,
                'user_id' => $member->id,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'status' => $status,
                'amount_owed' => $amount,
                'currency' => 'USD',
                'payment_state' => $paymentState,
                'payment_source' => $paymentSource,
                'note' => $note,
            ]);

            if ($discount !== null && $paymentState === PaymentState::Paid) {
                $this->discounts->redeem($discount, $membership, $booking, $points - $finalPoints);
            }

            // Inside the same transaction and the same Space lock as the
            // overlap check above, so the counter can never disagree with
            // the rows it is derived from.
            $this->capacitySlots->occupy($booking, $locked, $rules);

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

    /**
     * @return array{0: PaymentState, 1: ?PaymentSource}
     */
    private function routePayment(Space $space, User $member, int $points, ?OwnerType $walletOwnerType, ?int $walletOwnerId): array
    {
        if ($points <= 0) {
            return [PaymentState::Unpaid, null];
        }

        if ($walletOwnerType !== null && $walletOwnerId !== null) {
            $options = $this->wallets->spendOptions($member, WalletTransactionCategory::SpaceSpecific);
            $matched = collect($options)->first(
                fn (array $option) => $option['owner_type'] === $walletOwnerType->value && $option['owner_id'] === $walletOwnerId
            );

            if ($matched === null) {
                throw new ReceptionActionException('api.booking.wallet_not_owned');
            }

            if ($matched['usable_balance'] < $points) {
                return [PaymentState::Unpaid, null];
            }

            $wallet = $this->wallets->walletFor($walletOwnerType, $walletOwnerId);
            $this->wallets->debit($wallet, $member, WalletTransactionCategory::SpaceSpecific, $points, "Booking for space #{$space->id}");

            return [PaymentState::Paid, PaymentSource::Wallet];
        }

        $options = $this->wallets->spendOptions($member, WalletTransactionCategory::SpaceSpecific);

        if (count($options) > 1) {
            throw new WalletChoiceRequiredException($options);
        }

        if (count($options) === 1 && $options[0]['usable_balance'] >= $points) {
            $wallet = $this->wallets->walletFor(OwnerType::from($options[0]['owner_type']), $options[0]['owner_id']);
            $this->wallets->debit($wallet, $member, WalletTransactionCategory::SpaceSpecific, $points, "Booking for space #{$space->id}");

            return [PaymentState::Paid, PaymentSource::Wallet];
        }

        return [PaymentState::Unpaid, null];
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
        if ($this->availability->overlapCount($existing, $start, $end) >= $rules->concurrencyCeiling) {
            throw new ReceptionActionException('api.booking.slot_unavailable');
        }
    }

    /**
     * Present-moment physical-presence count, reusing
     * WalkInCapacityService::start()'s exact counting logic.
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
