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
    ) {}

    /**
     * A space that admits more than one booking at a time has no turnover
     * gap to protect — two people at two different desks do not need the
     * room cleared between them — so the buffer simply does not apply.
     */
    public function bufferApplies(): bool
    {
        return $this->bufferMinutes > 0 && $this->concurrencyCeiling === 1;
    }

    public function allowsConcurrentBookings(): bool
    {
        return $this->concurrencyCeiling > 1;
    }
}
