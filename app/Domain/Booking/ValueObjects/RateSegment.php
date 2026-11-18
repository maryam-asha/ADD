<?php

namespace App\Domain\Booking\ValueObjects;

/**
 * A stretch of time and the hourly rate (points) it is charged at — the
 * unit AmountCalculator::forSegments() sums before rounding once.
 */
final class RateSegment
{
    /** The hourly rate in points, exact: an offer's 10% off 15 is 13.5. */
    public readonly string $rate;

    public function __construct(
        public readonly int $seconds,
        int|string $rate,
    ) {
        $this->rate = (string) $rate;
    }
}
