<?php

namespace App\Domain\Pricing\Enums;

/**
 * What became of a coupon the member gave. `OfferIsBetter`: the coupon
 * does not combine with offers, and the offer already gives a lower price —
 * the purchase goes ahead at the offer, and the coupon is not spent.
 */
enum CouponOutcome: string
{
    case None = 'none';
    case Applied = 'applied';
    case OfferIsBetter = 'offer_is_better';
}
