<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Exceptions\BatchUnavailableException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\ValueObjects\PoolDemand;
use App\Domain\Booking\ValueObjects\SpaceBookingRules;
use App\Domain\Finance\Services\PointConversionService;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\NotificationLog;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\PlanDiscount;
use App\Domain\Membership\Services\MembershipResolver;
use App\Domain\Membership\Services\PlanDiscountService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The multi-date picker: several windows, one request, all or nothing.
 *
 * ## Why N rows rather than one booking with N windows
 *
 * `bookings` has no multi-session analogue to `event_sessions`, and adding
 * one would fork every consumer — check-in, check-out, extension,
 * cancellation, access-grant issuance, capacity counting and the reception
 * dashboard all assume one booking is one window. Each chosen date is
 * independently checked into, cancelled and priced, so one row per window
 * is the shape the rest of the domain already speaks.
 *
 * ## Why atomic rather than partial
 *
 * The screen shows one running total and one confirm. If the third of four
 * days failed after the wallet had been debited for the first two, the
 * member would be part-charged for a booking they never got, and the app
 * would need a reconciliation screen nobody designed. So: every window is
 * validated, then all are created, or nothing is.
 *
 * ## Deliberate limitation
 *
 * A batch is not a unit after creation. Cancelling one day does not cancel
 * the rest, and no `batch_id` links them — that is schema growth for a
 * grouping the member never asks to act on as a group. If "cancel my whole
 * multi-day booking" is ever wanted, a nullable `booking_group_id` is the
 * additive path.
 */
class BookingBatchCreationService
{
    /**
     * Bounds the number of row locks one request holds at once.
     */
    public const MAX_WINDOWS = 10;

    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly AmountCalculator $amounts,
        private readonly PointConversionService $points,
        private readonly MembershipResolver $membershipResolver,
        private readonly PlanDiscountService $discounts,
        private readonly BookingPaymentRouter $payments,
    ) {}

    /**
     * @param  list<array{space_id: int, start_at: string, end_at: string}>  $windows
     * @return list<Booking>
     */
    public function createMany(
        array $windows,
        User $member,
        ?OwnerType $walletOwnerType = null,
        ?int $walletOwnerId = null,
        ?string $note = null,
        ?string $paymentMethod = null,
    ): array {
        $normalized = $this->normalize($windows);

        // Stateless rules first, outside any lock — they depend on nothing
        // shared, so failing here costs no lock time.
        $failures = $this->statelessFailures($normalized);

        if ($failures !== []) {
            throw new BatchUnavailableException($failures);
        }

        return DB::transaction(function () use ($normalized, $member, $walletOwnerType, $walletOwnerId, $note, $paymentMethod) {
            // Lock every distinct space in ascending id order. Two
            // concurrent batches touching the same pair of spaces would
            // otherwise be free to grab them in opposite orders and
            // deadlock.
            $spaceIds = collect($normalized)->pluck('space_id')->unique()->sort()->values();

            // A co_space's seats are its branch's pool, so its branch is
            // locked first — branches before spaces, each in ascending id
            // order, the same order every other path takes
            // (docs/decisions/coworking-seat-pool.md).
            Space::query()
                ->whereIn('id', $spaceIds)
                ->with('building:id,branch_id')
                ->get()
                ->filter(fn (Space $space) => $this->availability->usesBranchPool($space))
                ->map(fn (Space $space) => $space->building->branch_id)
                ->unique()
                ->sort()
                ->each(fn (int $branchId) => Branch::query()->whereKey($branchId)->lockForUpdate()->first());

            $locked = $spaceIds
                ->mapWithKeys(fn (int $id) => [
                    $id => Space::query()->whereKey($id)->lockForUpdate()->firstOrFail(),
                ]);

            // Same member for the whole batch, resolved once rather than
            // per window (docs/decisions/plan-discounts-design.md).
            $membership = $this->membershipResolver->activeFor($member);

            // Usage a usage-limited discount has already been earmarked
            // for earlier in this same not-yet-persisted batch — none of it
            // has a PlanDiscountRedemption row yet for resolveFor()'s own
            // ledger count to see, so it must be tracked here instead.
            $consumedInBatch = [];

            $failures = [];
            /** @var array<int, Collection<int, Booking>> $blocking */
            $blocking = [];
            /** @var list<array{index: int, space: Space, start: CarbonImmutable, end: CarbonImmutable, points: int, discount: ?PlanDiscount, points_discounted: int}> $accepted */
            $accepted = [];

            foreach ($normalized as $window) {
                $space = $locked[$window['space_id']];

                // Off the locked row, as in BookingCreationService.
                if (! $this->availability->acceptsBookings($space)) {
                    $failures[] = $this->failure($window, 'api.booking.space_not_bookable');

                    continue;
                }

                $rules = $this->availability->rulesFor($space);

                if ($rules->usesBranchPool) {
                    $reasonKey = $this->poolFailure($space, $member, $window, $accepted);

                    if ($reasonKey !== null) {
                        $failures[] = $this->failure($window, $reasonKey);

                        continue;
                    }
                } elseif ($this->availability->isInCoworkingMode(
                    $this->availability->blockingCoworkingWindows($space, $window['start'], $window['end']),
                    $window['start'],
                    $window['end'],
                )) {
                    $failures[] = $this->failure($window, 'api.booking.room_in_coworking_mode');

                    continue;
                }

                // One fetch per space covering its whole requested span.
                $blocking[$space->id] ??= $this->spanFor($space, $normalized, $rules);

                // Windows already accepted in THIS batch count too — two
                // picked dates that overlap each other would otherwise both
                // pass, since neither is in the database yet.
                $candidates = $blocking[$space->id]->concat(
                    collect($accepted)
                        ->filter(fn (array $row) => $row['space']->id === $space->id)
                        ->map(fn (array $row) => new Booking([
                            'space_id' => $space->id,
                            'start_at' => $row['start'],
                            'end_at' => $row['end'],
                        ]))
                );

                $reasonKey = $this->contendedFailure($candidates, $window['start'], $window['end'], $rules);

                if ($reasonKey !== null) {
                    $failures[] = $this->failure($window, $reasonKey);

                    continue;
                }

                $rawPoints = $this->amounts->forRange($space, $window['start'], $window['end']);

                // Applied automatically, same as the single-booking path
                // (docs/decisions/plan-discounts-design.md). $consumedInBatch
                // stops a usage_limit of e.g. 1 from being claimed by more
                // than one window in this same batch, since none of them
                // have a redemption row yet for resolveFor()'s own count.
                $discount = $membership !== null ? $this->discounts->resolveFor($membership, $space, $consumedInBatch) : null;
                $finalPoints = $discount !== null ? $this->discounts->apply($rawPoints, $discount) : $rawPoints;

                if ($discount !== null) {
                    $consumedInBatch[$discount->id] = ($consumedInBatch[$discount->id] ?? 0) + 1;
                }

                $accepted[] = [
                    'index' => $window['index'],
                    'space' => $space,
                    'start' => $window['start'],
                    'end' => $window['end'],
                    'points' => $finalPoints,
                    'discount' => $discount,
                    'points_discounted' => $rawPoints - $finalPoints,
                ];
            }

            if ($failures !== []) {
                // Rolls back the whole transaction — nothing created, no
                // wallet touched.
                throw new BatchUnavailableException($failures);
            }

            $totalPoints = collect($accepted)->sum('points');

            // Routed once for the summed total, so a member with two
            // eligible wallets is asked to choose once rather than N times,
            // and a wallet that covers some dates but not all pays for none.
            // Each booking then gets its own payment row and debit, inside
            // this transaction — still all or nothing.
            $payingWallet = $this->payments->choose(
                $member,
                $totalPoints,
                $paymentMethod,
                $walletOwnerType,
                $walletOwnerId,
            );

            $created = [];

            foreach ($accepted as $row) {
                $space = $row['space'];
                $status = $space->requires_approval ? BookingStatus::Pending : BookingStatus::Confirmed;

                $booking = Booking::create([
                    'space_id' => $space->id,
                    // Placed one by one, so each sees the ones before it.
                    'seat_space_id' => $this->availability->usesBranchPool($space)
                        ? $this->availability->seatFor($space, $row['start'], $row['end'], $member)?->id
                        : null,
                    'user_id' => $member->id,
                    'start_at' => $row['start'],
                    'end_at' => $row['end'],
                    'status' => $status,
                    'amount_owed' => $this->points->pointsToUsd($row['points']),
                    'currency' => 'USD',
                    'price_points' => $row['points'],
                    'payment_state' => PaymentState::Unpaid,
                    'note' => $note,
                ]);

                if ($payingWallet !== null && $row['points'] > 0) {
                    $this->payments->pay($booking, $member, $payingWallet);
                }

                // Used up whether the booking is paid now or later, as in
                // BookingCreationService.
                if ($row['discount'] !== null) {
                    $this->discounts->redeem($row['discount'], $membership, $booking, $row['points_discounted']);
                }

                if ($status === BookingStatus::Pending) {
                    $this->notifyOperations();
                }

                $created[] = $booking;
            }

            return $created;
        });
    }

    /**
     * Parse and sort, keeping each window's original index so a failure can
     * name the one the member picked.
     *
     * @param  list<array{space_id: int, start_at: string, end_at: string}>  $windows
     * @return list<array{index: int, space_id: int, start: CarbonImmutable, end: CarbonImmutable}>
     */
    private function normalize(array $windows): array
    {
        $normalized = [];

        foreach (array_values($windows) as $index => $window) {
            $normalized[] = [
                'index' => $index,
                'space_id' => (int) $window['space_id'],
                'start' => CarbonImmutable::parse($window['start_at'], 'UTC'),
                'end' => CarbonImmutable::parse($window['end_at'], 'UTC'),
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<array{index: int, space_id: int, start: CarbonImmutable, end: CarbonImmutable}>  $windows
     * @return list<array{index: int, start_at: string, reason: string, message_key: string}>
     */
    private function statelessFailures(array $windows): array
    {
        $failures = [];
        $spaces = Space::query()
            ->whereIn('id', collect($windows)->pluck('space_id')->unique())
            ->with('building.branch')
            ->get()
            ->keyBy('id');

        foreach ($windows as $window) {
            $space = $spaces->get($window['space_id']);

            if ($space === null) {
                $failures[] = $this->failure($window, 'api.system.not_found');

                continue;
            }

            $rules = $this->availability->rulesFor($space);

            $reasonKey = match (true) {
                $window['start']->lessThan(now()) => 'api.booking.start_in_the_past',
                ! $this->availability->isFarEnoughAhead($window['start'], $rules) => 'api.booking.too_soon',
                ! $this->availability->isWithinAdvanceHorizon($window['start'], $rules) => 'api.booking.too_far_ahead',
                ! $this->availability->isWithinBusinessHours($space, $window['start'], $window['end'], $rules) => 'api.reception.outside_business_hours',
                ! $this->availability->isValidGranularity($window['start'], $rules) => 'api.booking.invalid_start_time',
                ! $this->availability->isLongEnough($window['start'], $window['end'], $rules) => 'api.booking.duration_too_short',
                ! $this->availability->matchesDurationGranularity($window['start'], $window['end'], $rules) => 'api.booking.duration_invalid_granularity',
                default => null,
            };

            if ($reasonKey !== null) {
                $failures[] = $this->failure($window, $reasonKey);
            }
        }

        return $failures;
    }

    /**
     * A co_space window needs a seat in its branch's pool. Two windows of
     * the same batch on the same pool at once are one member in two seats,
     * which no one needs, so the second is refused outright rather than
     * counted.
     *
     * @param  array{index: int, space_id: int, start: CarbonImmutable, end: CarbonImmutable}  $window
     * @param  list<array{space: Space, start: CarbonImmutable, end: CarbonImmutable}>  $accepted
     */
    private function poolFailure(Space $space, User $member, array $window, array $accepted): ?string
    {
        $samePoolAtOnce = collect($accepted)->contains(
            fn (array $row) => $this->availability->usesBranchPool($row['space'])
                && $row['space']->building->branch_id === $space->building->branch_id
                && $row['start'] < $window['end'] && $row['end'] > $window['start']
        );

        $timeline = $this->availability->poolTimeline(
            $space,
            $window['start'],
            $window['end'],
            PoolDemand::presence($window['start'], $window['end'], $member),
        );

        return $samePoolAtOnce || $this->availability->exceedsPool($timeline, $window['start'], $window['end'])
            ? 'api.booking.slot_unavailable'
            : null;
    }

    /**
     * @param  Collection<int, Booking>  $candidates
     */
    private function contendedFailure(
        Collection $candidates,
        CarbonImmutable $start,
        CarbonImmutable $end,
        SpaceBookingRules $rules,
    ): ?string {
        if (! $rules->usesBranchPool && $this->availability->exceedsCeiling($candidates, $start, $end, $rules)) {
            return 'api.booking.slot_unavailable';
        }

        if ($this->availability->violatesBuffer($candidates, $start, $end, $rules)) {
            return 'api.booking.buffer_conflict';
        }

        return null;
    }

    /**
     * Every blocking booking across this space's whole requested span, in
     * one query rather than one per window.
     *
     * @param  list<array{index: int, space_id: int, start: CarbonImmutable, end: CarbonImmutable}>  $windows
     * @return Collection<int, Booking>
     */
    private function spanFor(Space $space, array $windows, SpaceBookingRules $rules): Collection
    {
        $forSpace = collect($windows)->where('space_id', $space->id);

        return $this->availability->blockingBookings(
            $space,
            $forSpace->min('start'),
            $forSpace->max('end'),
            $rules,
        );
    }

    /**
     * @param  array{index: int, space_id: int, start: CarbonImmutable, end: CarbonImmutable}  $window
     * @return array{index: int, start_at: string, reason: string, message_key: string}
     */
    private function failure(array $window, string $messageKey): array
    {
        return [
            'index' => $window['index'],
            'start_at' => $window['start']->toIso8601String(),
            'reason' => str_contains($messageKey, '.') ? substr($messageKey, strrpos($messageKey, '.') + 1) : $messageKey,
            'message_key' => $messageKey,
        ];
    }

    private function notifyOperations(): void
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
}
