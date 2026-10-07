<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Booking\ValueObjects\RateSegment;
use App\Domain\Foundation\Models\Space;
use App\Domain\Pricing\Enums\CouponDiscountType;
use App\Domain\Pricing\ValueObjects\CouponTerms;
use App\Domain\Pricing\ValueObjects\SaleWindow;
use Carbon\CarbonInterface;

/**
 * The rates a range of time in a space is charged at under an offer: the
 * seconds inside the offer's window at the offer's rate, the rest at the
 * normal rate — priced by USAGE time
 * (docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md, E3).
 *
 * Returns the stretches for AmountCalculator::forSegments(), which rounds
 * once over all of them; the offer's rate is exact (10% off 15 is 13.5). An
 * offer that gives no lower rate is ignored.
 */
class SpaceRateTimeline
{
    /**
     * @return list<RateSegment>
     */
    public function segments(Space $space, CarbonInterface $from, CarbonInterface $to, ?CouponTerms $offer = null): array
    {
        $rate = $space->hourly_rate_points;
        $total = (int) $from->diffInSeconds($to);

        if ($rate === null || $total <= 0) {
            return [];
        }

        $offerRate = $offer !== null ? $this->offerRate((int) $rate, $offer) : null;
        $inside = $offerRate !== null ? ($offer->window ?? SaleWindow::between(null, null))->clip($from, $to) : null;

        if ($inside === null) {
            return [new RateSegment($total, (int) $rate)];
        }

        $offerSeconds = (int) $inside[0]->diffInSeconds($inside[1]);
        $segments = [new RateSegment($offerSeconds, $offerRate)];

        if ($total > $offerSeconds) {
            $segments[] = new RateSegment($total - $offerSeconds, (int) $rate);
        }

        return $segments;
    }

    /**
     * The hourly rate inside the offer, exact — `percentage` takes p% off
     * the rate, `amount_off` that many points (never below 0), `fixed_price`
     * is the rate itself — or null when it is no lower than the rate.
     */
    public function offerRate(int $rate, CouponTerms $offer): ?string
    {
        $offered = match ($offer->type) {
            CouponDiscountType::Percentage => bcdiv(bcmul((string) $rate, (string) (100 - $offer->value)), '100', 2),
            CouponDiscountType::AmountOff => (string) max(0, $rate - $offer->value),
            CouponDiscountType::FixedPrice => (string) $offer->value,
        };

        return bccomp($offered, (string) $rate, 2) < 0 ? $offered : null;
    }
}
