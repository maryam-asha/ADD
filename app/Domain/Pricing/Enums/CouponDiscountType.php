<?php

namespace App\Domain\Pricing\Enums;

/**
 * `percentage` takes `discount_value`% off (floored, optionally capped by
 * `max_discount_points`); `amount_off` takes a flat `discount_value` points
 * off. Neither ever takes off more than the price it applies to.
 */
enum CouponDiscountType: string
{
    case Percentage = 'percentage';
    case AmountOff = 'amount_off';
}
