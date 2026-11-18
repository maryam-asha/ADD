<?php

namespace App\Domain\Pricing\Enums;

/** What an admin did to an offer or a coupon, as its history records it. */
enum CouponRevisionAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
}
