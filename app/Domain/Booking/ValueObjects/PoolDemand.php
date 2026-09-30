<?php

namespace App\Domain\Booking\ValueObjects;

use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Seats something not yet saved would take, tried against the co-working
 * pool before it is allowed: a booking or walk-in by a member (counted on
 * the subscription that covers them, if any), or a subscription about to be
 * sold (holding its seats while its plan is open).
 */
final readonly class PoolDemand
{
    private function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public ?User $user = null,
        public ?Plan $plan = null,
        public int $seats = 1,
        public ?int $ignoreBookingId = null,
    ) {}

    /**
     * A member present over [$start, $end): a booking, or a walk-in. With
     * `$ignoreBookingId`, it stands in for that booking — an extension is
     * tried without the window it replaces.
     */
    public static function presence(CarbonInterface $start, CarbonInterface $end, ?User $user, ?int $ignoreBookingId = null): self
    {
        return new self(CarbonImmutable::instance($start)->utc(), CarbonImmutable::instance($end)->utc(), user: $user, ignoreBookingId: $ignoreBookingId);
    }

    /** A subscription to `$plan` holding `$seats` seats over [$start, $end). */
    public static function subscription(Plan $plan, int $seats, CarbonInterface $start, CarbonInterface $end): self
    {
        return new self(CarbonImmutable::instance($start)->utc(), CarbonImmutable::instance($end)->utc(), plan: $plan, seats: $seats);
    }

    public function isSubscription(): bool
    {
        return $this->plan !== null;
    }
}
