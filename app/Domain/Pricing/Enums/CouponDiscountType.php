<?php

namespace App\Domain\Pricing\Enums;

/**
 * `percentage` takes `discount_value`% off (floored, optionally capped by
 * `max_discount_points`); `amount_off` takes a flat `discount_value` points
 * off. Neither ever takes off more than the price it applies to.
 *
 * Offers and coupons share these two. A third, `fixed_price` (the price
 * itself), was dropped on 2026-10-08: on one item it is a number of points
 * off, and on several it read differently per item.
 */
enum CouponDiscountType: string
{
    case Percentage = 'percentage';
    case AmountOff = 'amount_off';
}
