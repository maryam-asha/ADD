<?php

namespace App\Domain\Pricing\Enums;

/**
 * `percentage` takes `discount_value`% off (floored, optionally capped by
 * `max_discount_points`); `amount_off` takes a flat `discount_value` points
 * off. Neither ever takes off more than the price it applies to.
 *
 * `fixed_price` is for automatic offers only: `discount_value` is then the
 * price itself — points per hour for time in a space, points for a plan or
 * an event. A fixed price at or above the normal one takes nothing off.
 */
enum CouponDiscountType: string
{
    case Percentage = 'percentage';
    case AmountOff = 'amount_off';
    case FixedPrice = 'fixed_price';
}
