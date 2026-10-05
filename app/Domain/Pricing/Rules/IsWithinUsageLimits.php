<?php

namespace App\Domain\Pricing\Rules;

use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Enums\CouponRejection;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Services\CouponLedger;
use App\Domain\Pricing\ValueObjects\PricingTarget;
use App\Domain\Pricing\ValueObjects\UsageInFlight;
use Carbon\CarbonInterface;

/**
 * "The first N": live uses of everyone under `usage_limit`, and of this
 * member under `per_user_limit` — counting uses earmarked earlier in the
 * same batch. Only reliable under the coupon's row lock (CouponLedger).
 */
class IsWithinUsageLimits implements CouponRule
{
    public function __construct(private readonly CouponLedger $ledger) {}

    public function rejection(Coupon $coupon, User $member, PricingTarget $target, UsageInFlight $inFlight, CarbonInterface $at): ?CouponRejection
    {
        $pending = $inFlight->coupon($coupon);

        if ($coupon->usage_limit !== null && $this->ledger->liveUsage($coupon) + $pending >= $coupon->usage_limit) {
            return CouponRejection::UsageLimitReached;
        }

        if ($coupon->per_user_limit !== null && $this->ledger->liveUsage($coupon, $member) + $pending >= $coupon->per_user_limit) {
            return CouponRejection::PerUserLimitReached;
        }

        return null;
    }
}
