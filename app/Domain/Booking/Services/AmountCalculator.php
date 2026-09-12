<?php

namespace App\Domain\Booking\Services;

use App\Domain\Foundation\Models\Space;
use Carbon\CarbonInterface;

/**
 * Charges are whole points, rounded up (Invariant 2, debit direction):
 * a partial point always costs a full one, so a booking never charges
 * less than it consumed.
 *
 * Computed with bcmath at scale 4 and ceiled once at the very end —
 * rounding per step would compound. Returns a bare int because points
 * carry no currency; the old [amount, currency] tuple is gone.
 */
class AmountCalculator
{
    public function forRange(Space $space, CarbonInterface $start, CarbonInterface $end): int
    {
        $rate = $space->hourly_rate_points;

        if ($rate === null || $rate <= 0) {
            return 0;
        }

        $seconds = $start->diffInSeconds($end);
        $hours = bcdiv((string) $seconds, '3600', 6);
        $exact = bcmul($hours, (string) $rate, 4);

        $floor = bcdiv($exact, '1', 0);

        return (int) (bccomp($exact, $floor, 4) > 0 ? bcadd($floor, '1', 0) : $floor);
    }
}
