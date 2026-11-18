<?php

namespace App\Domain\Booking\ValueObjects;

use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\ValueObjects\HourAllowance;

/**
 * What a member's plan covers of one booking (BookingPlanCoverage): the
 * seconds, from which allowance, and whether that allowance is the
 * subscriber's own coworking seat — which already holds them a seat, so a
 * booking it covers entirely is refused.
 */
final class BookingCoverage
{
    public function __construct(
        public readonly ?Membership $membership,
        public readonly ?HourAllowance $allowance,
        public readonly int $coveredSeconds,
        public readonly bool $seatCovered,
        /** Groups bookings that spend the same hours: allowance and cycle. */
        public readonly ?string $allowanceKey = null,
    ) {}

    public static function none(): self
    {
        return new self(null, null, 0, false);
    }
}
