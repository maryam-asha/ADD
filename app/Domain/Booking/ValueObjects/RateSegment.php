<?php

namespace App\Domain\Booking\ValueObjects;

/**
 * A stretch of time and the hourly rate (points) it is charged at — the
 * unit AmountCalculator::forSegments() sums before rounding once.
 */
final class RateSegment
{
    public function __construct(
        public readonly int $seconds,
        public readonly int $ratePoints,
    ) {}
}
