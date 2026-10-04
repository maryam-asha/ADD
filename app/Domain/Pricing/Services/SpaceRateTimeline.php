<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Booking\ValueObjects\RateSegment;
use App\Domain\Foundation\Models\Space;
use Carbon\CarbonInterface;

/**
 * The rates a range of time in a space is charged at: the seconds inside the
 * space's sale window at the sale rate, the rest at its normal rate — a sale
 * priced by USAGE time (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md).
 *
 * Returns the stretches for AmountCalculator::forSegments(), which rounds
 * once over all of them. A sale rate at or above the normal one is not a
 * sale, and is ignored.
 */
class SpaceRateTimeline
{
    /**
     * @return list<RateSegment>
     */
    public function segments(Space $space, CarbonInterface $from, CarbonInterface $to): array
    {
        $rate = $space->hourly_rate_points;
        $total = (int) $from->diffInSeconds($to);

        if ($rate === null || $total <= 0) {
            return [];
        }

        $sale = $space->saleWindow();
        $inside = $sale !== null && $sale->points < $rate ? $sale->clip($from, $to) : null;

        if ($inside === null) {
            return [new RateSegment($total, (int) $rate)];
        }

        $saleSeconds = (int) $inside[0]->diffInSeconds($inside[1]);
        $segments = [new RateSegment($saleSeconds, $sale->points)];

        if ($total > $saleSeconds) {
            $segments[] = new RateSegment($total - $saleSeconds, (int) $rate);
        }

        return $segments;
    }
}
