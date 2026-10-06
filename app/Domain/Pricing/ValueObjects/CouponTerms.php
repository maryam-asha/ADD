<?php

namespace App\Domain\Pricing\ValueObjects;

use App\Domain\Pricing\Enums\CouponDiscountType;
use App\Domain\Pricing\Models\AppliedDiscount;
use App\Domain\Pricing\Models\Coupon;

/**
 * The part of a coupon that decides how much it takes off. Read from the
 * coupon for a new price, and from a redemption's copy for a price already
 * locked — so editing a coupon never moves a price it set.
 */
final class CouponTerms
{
    public function __construct(
        public readonly CouponDiscountType $type,
        public readonly int $value,
        public readonly ?int $maxDiscountPoints = null,
    ) {}

    public static function fromCoupon(Coupon $coupon): self
    {
        return new self($coupon->discount_type, (int) $coupon->discount_value, $coupon->max_discount_points);
    }

    public static function fromLedger(AppliedDiscount $redemption): self
    {
        return new self($redemption->discount_type, (int) $redemption->discount_value, $redemption->max_discount_points);
    }
}
