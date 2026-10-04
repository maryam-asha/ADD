<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\ValueObjects\RateSegment;
use App\Domain\Foundation\Models\Space;
use Carbon\CarbonInterface;

/**
 * Charges are whole points, rounded up (Invariant 2, debit direction):
 * a partial point always costs a full one, so a booking never charges
 * less than it consumed.
 *
 * Computed entirely in integers — seconds * rate is divided by 3600 once,
 * and the remainder of that single division is what decides the ceil. No
 * intermediate value is ever rounded or truncated, so the ceil always sees
 * the exact amount; rounding per step would compound and, because every
 * such loss is invisible to the final comparison, silently under-charge.
 * Returns a bare int because points carry no currency; the old
 * [amount, currency] tuple is gone.
 */
class AmountCalculator
{
    private const SECONDS_PER_HOUR = 3600;

    public function forRange(Space $space, CarbonInterface $start, CarbonInterface $end): int
    {
        $rate = $space->hourly_rate_points;

        if ($rate === null) {
            return 0;
        }

        return $this->forSegments([new RateSegment((int) $start->diffInSeconds($end), (int) $rate)]);
    }

    /**
     * Several stretches at their own rates — a range part of which falls in
     * a sale — summed exactly and rounded up once over the whole, so a
     * partial point is never charged twice. A stretch with no time or no
     * rate costs nothing.
     *
     * @param  list<RateSegment>  $segments
     */
    public function forSegments(array $segments): int
    {
        $points = '0';

        foreach ($segments as $segment) {
            if ($segment->seconds <= 0 || $segment->ratePoints <= 0) {
                continue;
            }

            $points = bcadd($points, bcmul((string) $segment->seconds, (string) $segment->ratePoints));
        }

        $whole = bcdiv($points, (string) self::SECONDS_PER_HOUR, 0);
        $remainder = bcmod($points, (string) self::SECONDS_PER_HOUR);

        return (int) (bccomp($remainder, '0') > 0 ? bcadd($whole, '1') : $whole);
    }
}
