<?php

namespace App\Domain\Pricing\ValueObjects;

use App\Domain\Pricing\Enums\CouponDiscountType;
use App\Domain\Pricing\Models\AppliedDiscount;
use App\Domain\Pricing\Models\Coupon;
use Carbon\CarbonImmutable;

/**
 * The part of a coupon or offer that decides how much it takes off — and,
 * for an offer on time in a space, the window it takes it off in. Read from
 * the coupon for a new price, and from a ledger row's copy for a price
 * already locked — so editing a coupon or offer never moves a price it set.
 */
final class CouponTerms
{
    public function __construct(
        public readonly CouponDiscountType $type,
        public readonly int $value,
        public readonly ?int $maxDiscountPoints = null,
        public readonly ?SaleWindow $window = null,
    ) {}

    public static function fromCoupon(Coupon $coupon): self
    {
        return new self($coupon->discount_type, (int) $coupon->discount_value, $coupon->max_discount_points, $coupon->window());
    }

    public static function fromLedger(AppliedDiscount $row): self
    {
        $terms = $row->terms ?? [];

        return new self(
            $row->discount_type,
            (int) $row->discount_value,
            $row->max_discount_points,
            SaleWindow::between(
                isset($terms['starts_at']) ? CarbonImmutable::parse($terms['starts_at']) : null,
                isset($terms['ends_at']) ? CarbonImmutable::parse($terms['ends_at']) : null,
            ),
        );
    }
}
