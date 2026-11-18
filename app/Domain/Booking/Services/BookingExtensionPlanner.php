<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\ExtensionBlockReason;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\SpaceCoworkingWindow;
use App\Domain\Booking\ValueObjects\ExtensionOptions;
use App\Domain\Booking\ValueObjects\PoolDemand;
use App\Domain\Booking\ValueObjects\SpaceBookingRules;
use App\Domain\Foundation\Models\Space;
use App\Domain\Foundation\Services\BusinessHoursService;
use App\Domain\Membership\Models\PlanDiscountRedemption;
use App\Domain\Membership\Services\MembershipResolver;
use App\Domain\Pricing\Services\PriceQuoteService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The one definition of whether, how far and at what price a booking can be
 * extended (docs/decisions/booking-outcomes-and-extension.md). The options
 * endpoint, each booking's `can_extend`, and BookingExtensionService — which
 * calls it again under its locks — all read it, so the app is never offered
 * an extension the POST would refuse.
 *
 * The availability itself is AvailabilityService's: this composes its reads
 * (blocking bookings with their buffer, co-working loans, the seat pool)
 * rather than restating them.
 */
class BookingExtensionPlanner
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly BusinessHoursService $businessHours,
        private readonly MembershipResolver $membershipResolver,
        private readonly PriceQuoteService $quotes,
    ) {}

    /**
     * Whether the booking itself may be extended: the space allows it, the
     * member is in the room, and its time is running. No query beyond the
     * booking's space, so options() answers a list card that is not running
     * without touching the database.
     */
    public function blockReason(Booking $booking, Space $space, ?CarbonInterface $now = null): ?ExtensionBlockReason
    {
        $now ??= now();

        // Null only on a space not re-read since it was created: the
        // column's default applies.
        if (! ($space->allows_extension ?? true)) {
            return ExtensionBlockReason::SpaceDisallows;
        }

        if ($booking->status !== BookingStatus::Confirmed || $booking->checked_in_at === null || $booking->checked_out_at !== null) {
            return ExtensionBlockReason::NotCheckedIn;
        }

        if ($now->lessThan($booking->start_at) || $now->greaterThanOrEqualTo($booking->end_at)) {
            return ExtensionBlockReason::OutsideBookingTime;
        }

        return null;
    }

    /**
     * The furthest the booking can run, and what stops it there: the
     * branch's closing, or — when it comes first — the next booking less its
     * buffer, a co-working loan of the room, or the seat pool running out.
     *
     * @return array{0: CarbonImmutable, 1: ExtensionBlockReason}
     */
    public function latestEnd(Booking $booking, Space $space, SpaceBookingRules $rules): array
    {
        $end = CarbonImmutable::instance($booking->end_at)->utc();
        $closing = $this->closingAfter($space, $end, $rules);

        if ($closing->lessThanOrEqualTo($end)) {
            return [$end, ExtensionBlockReason::BranchClosing];
        }

        $stop = $rules->usesBranchPool
            ? $this->availability->poolTimeline(
                $space,
                $end,
                $closing,
                PoolDemand::presence($end, $closing, $booking->user, ignoreBookingId: $booking->id),
            )->firstShortfall()
            : $this->roomStop($booking, $space, $rules, $end, $closing);

        if ($stop === null || $stop->greaterThanOrEqualTo($closing)) {
            return [$closing, ExtensionBlockReason::BranchClosing];
        }

        $stop = $stop->max($end);

        return [$stop, $this->seatRoomClosesAt($booking, $stop) ? ExtensionBlockReason::SeatRoomCloses : ExtensionBlockReason::NextSlotBooked];
    }

    /**
     * Whether the pool runs out exactly when the lent room the member sits
     * in goes back to being a room — the member is told that, not that
     * the next slot is booked (docs/decisions/coworking-seat-pool.md,
     * "Seating placement").
     */
    private function seatRoomClosesAt(Booking $booking, CarbonImmutable $stop): bool
    {
        return $booking->seat_space_id !== null && SpaceCoworkingWindow::query()
            ->where('space_id', $booking->seat_space_id)
            ->where('ends_at', $stop)
            ->exists();
    }

    /**
     * Every legal extension length up to the latest end, each priced as
     * BookingExtensionService would charge it.
     */
    public function options(Booking $booking, ?CarbonInterface $now = null): ExtensionOptions
    {
        $space = $booking->space;
        $end = CarbonImmutable::instance($booking->end_at)->utc();

        if (($reason = $this->blockReason($booking, $space, $now)) !== null) {
            return new ExtensionOptions($reason, $end, $end, []);
        }

        $rules = $this->availability->rulesFor($space);
        [$latest, $stopReason] = $this->latestEnd($booking, $space, $rules);
        $redemption = $this->activeRedemption($booking);

        // The lengths isValidDurationInMinutes() accepts: the minimum
        // booking length, then whole steps of the space's granularity.
        $step = max(1, $rules->granularityMinutes);
        $options = [];

        for ($minutes = max($step, $rules->minDurationMinutes); $end->addMinutes($minutes)->lessThanOrEqualTo($latest); $minutes += $step) {
            $newEnd = $end->addMinutes($minutes);
            $options[] = [
                'minutes' => $minutes,
                'end_at' => $newEnd,
                'points' => $this->price($booking, $space, $newEnd, $redemption)['difference'],
            ];
        }

        return new ExtensionOptions($options === [] ? $stopReason : null, $end, $latest, $options);
    }

    /**
     * The plan discount the booking was made with, while the membership that
     * gave it is still active — a discounted booking keeps being discounted
     * as it grows (docs/decisions/plan-discounts-design.md).
     */
    public function activeRedemption(Booking $booking): ?PlanDiscountRedemption
    {
        $redemption = PlanDiscountRedemption::where('booking_id', $booking->id)->first();

        return $redemption !== null && $this->membershipResolver->activeFor($booking->user) !== null
            ? $redemption
            : null;
    }

    /**
     * What growing the booking to $newEnd costs — PriceQuoteService's, the
     * one place a price is composed: the booking's plan discount while it
     * is still active, else the offer it was priced with, at its copied
     * terms, by usage time, else the list price; never a coupon.
     *
     * @return array{difference: int, list_difference: int, total: int, undiscounted_total: int}
     */
    public function price(Booking $booking, Space $space, CarbonInterface $newEnd, ?PlanDiscountRedemption $redemption): array
    {
        return $this->quotes->forExtension($booking, $space, $newEnd, $redemption);
    }

    /**
     * The earliest moment after $end that another booking (less the buffer
     * it is owed) or a co-working loan takes the room, or null.
     */
    private function roomStop(Booking $booking, Space $space, SpaceBookingRules $rules, CarbonImmutable $end, CarbonImmutable $closing): ?CarbonImmutable
    {
        $buffer = $rules->bufferApplies() ? $rules->bufferMinutes : 0;

        $bookings = $this->availability->blockingBookings($space, $end, $closing, $rules)
            ->reject(fn (Booking $row) => $row->getKey() === $booking->getKey())
            // Still running, or yet to come, when this one ends.
            ->filter(fn (Booking $row) => $row->end_at->greaterThan($end))
            ->map(fn (Booking $row) => CarbonImmutable::instance($row->start_at)->utc()->subMinutes($buffer));

        $loans = $this->availability->blockingCoworkingWindows($space, $end, $closing)
            ->map(fn (SpaceCoworkingWindow $window) => CarbonImmutable::instance($window->starts_at)->utc());

        return $bookings->concat($loans)
            ->map(fn (CarbonImmutable $at) => $at->max($end))
            ->sort()
            ->first();
    }

    /**
     * When the branch's open period that $end falls in closes; $end itself
     * when it falls in none (closed, or ending exactly at closing).
     */
    private function closingAfter(Space $space, CarbonImmutable $end, SpaceBookingRules $rules): CarbonImmutable
    {
        $local = $end->setTimezone($rules->timezone);
        $time = $local->format('H:i');

        foreach ($this->businessHours->periodsFor($local, $space->building->branch) as $period) {
            if ($time >= $period['open_time'] && $time < $period['close_time']) {
                return $local->setTimeFromTimeString($period['close_time'])->utc();
            }
        }

        return $end;
    }
}
