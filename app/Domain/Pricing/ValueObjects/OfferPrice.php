<?php

namespace App\Domain\Pricing\ValueObjects;

use App\Domain\Pricing\Models\Coupon;
use Carbon\CarbonImmutable;

/**
 * An automatic offer and the price it gives — a whole range of time, a
 * plan, an event, or (OfferResolver::hourlyNow) the hourly rate shown — and
 * when it ends, null for an offer with no end.
 */
final class OfferPrice
{
    public function __construct(
        public readonly Coupon $offer,
        public readonly int $points,
        public readonly ?CarbonImmutable $endsAt,
    ) {}
}
