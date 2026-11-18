<?php

namespace App\Domain\Pricing\ValueObjects;

use App\Domain\Membership\Models\PlanDiscount;
use App\Domain\Pricing\Models\Coupon;

/**
 * Uses earmarked by windows of one batch that have no redemption row yet,
 * so the next window's limits see them — for plan discounts (keyed by
 * plan_discount_id, the array PlanDiscountService::resolveFor() takes) and
 * for coupons. One batch is one member's, so a coupon's in-flight count
 * weighs on its total and per-member limits alike.
 */
final class UsageInFlight
{
    /** @var array<int, int> */
    private array $planDiscounts = [];

    /** @var array<int, int> */
    private array $coupons = [];

    /** @return array<int, int> plan_discount_id => uses */
    public function planDiscounts(): array
    {
        return $this->planDiscounts;
    }

    public function addPlanDiscount(PlanDiscount $discount): void
    {
        $this->planDiscounts[$discount->id] = ($this->planDiscounts[$discount->id] ?? 0) + 1;
    }

    public function coupon(Coupon $coupon): int
    {
        return $this->coupons[$coupon->id] ?? 0;
    }

    public function addCoupon(Coupon $coupon): void
    {
        $this->coupons[$coupon->id] = $this->coupon($coupon) + 1;
    }
}
