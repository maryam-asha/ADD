<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Enums\CouponDiscountType;
use App\Domain\Pricing\ValueObjects\CouponTerms;

/**
 * What a coupon takes off a price, in whole points. A percentage floors —
 * the member never gets more than it strictly grants, the direction
 * PlanDiscountService::apply() rounds in — an amount is taken whole, the
 * cap limits either, and nothing takes off more than the price.
 */
class CouponCalculator
{
    public function discountFor(CouponTerms $terms, int $base): int
    {
        if ($base <= 0) {
            return 0;
        }

        $discount = match ($terms->type) {
            CouponDiscountType::Percentage => (int) bcdiv(bcmul((string) $base, (string) $terms->value), '100', 0),
            CouponDiscountType::AmountOff => $terms->value,
            // An offer's price: whatever is above it comes off.
            CouponDiscountType::FixedPrice => max(0, $base - $terms->value),
        };

        if ($terms->maxDiscountPoints !== null) {
            $discount = min($discount, $terms->maxDiscountPoints);
        }

        return max(0, min($discount, $base));
    }
}
