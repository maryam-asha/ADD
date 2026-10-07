<?php

namespace App\Domain\Pricing\Rules;

use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Enums\CouponRejection;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\ValueObjects\PricingTarget;
use App\Domain\Pricing\ValueObjects\UsageInFlight;
use Carbon\CarbonInterface;

/**
 * One condition a coupon must meet to be used. CouponEligibility runs them
 * in order and stops at the first refusal; a later phase (a gift code's
 * recipient, a referral's "not your own code") adds a rule of its own.
 *
 * A null target asks only whether the member may use the coupon at all —
 * "my coupons" with nothing chosen yet — so what it is for is not checked.
 */
interface CouponRule
{
    public function rejection(Coupon $coupon, User $member, ?PricingTarget $target, UsageInFlight $inFlight, CarbonInterface $at): ?CouponRejection;
}
