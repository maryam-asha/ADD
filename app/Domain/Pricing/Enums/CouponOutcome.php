<?php

namespace App\Domain\Pricing\Enums;

/**
 * What became of a coupon the member gave. One the offer beats is not an
 * outcome: it is refused (CouponRejection::OfferIsBetter).
 */
enum CouponOutcome: string
{
    case None = 'none';
    case Applied = 'applied';
}
