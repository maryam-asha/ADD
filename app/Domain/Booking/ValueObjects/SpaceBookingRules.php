<?php

namespace App\Domain\Booking\ValueObjects;

/**
 * Every tunable that decides whether a window is bookable, resolved once.
 *
 * Each of these is a `space.<column> ?? setting('booking.<key>')` fallback.
 * Reading them per slot meant a Setting lookup 96 times a day and 3000
 * times a month; resolving them into one immutable object at the top of a
 * request is what makes the calendar affordable
 * (docs/decisions/booking-availability-and-calendar.md).
 *
 * `concurrencyCeiling` is the one value that is derived rather than
 * configured: a co_space sells N seats in the same window, every other
 * space type is exclusive. See AvailabilityService::rulesFor().
 */
final readonly class SpaceBookingRules
{
    public function __construct(
        public int $granularityMinutes,
        public int $minDurationMinutes,
        public int $bufferMinutes,
        public int $cancellationWindowMinutes,
        public bool $requiresApproval,
        public ?int $capacity,
        public int $concurrencyCeiling,
        public string $timezone,
        // There is no ceiling on a booking's length: a member may book as
        // long as the day holds. Null means "no horizon"; a zero in the
        // settings table reads the same way, so an admin clearing the value
        // does not accidentally forbid every booking.
        public int $minNoticeMinutes = 0,
        public ?int $maxDaysAhead = null,
        // A co_space draws on its branch's co-working seat pool rather than a
        // ceiling of its own (docs/decisions/coworking-seat-pool.md).
        public bool $usesBranchPool = false,
    ) {}

    /**
     * A space that admits more than one booking at a time has no turnover
     * gap to protect — two people at two different desks do not need the
     * room cleared between them — so the buffer simply does not apply.
     */
    public function bufferApplies(): bool
    {
        return $this->bufferMinutes > 0 && ! $this->usesBranchPool && $this->concurrencyCeiling === 1;
    }

    public function allowsConcurrentBookings(): bool
    {
        return $this->usesBranchPool || $this->concurrencyCeiling > 1;
    }
}
