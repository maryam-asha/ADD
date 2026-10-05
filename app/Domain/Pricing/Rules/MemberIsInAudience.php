<?php

namespace App\Domain\Pricing\Rules;

use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Enums\CouponRejection;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\ValueObjects\PricingTarget;
use App\Domain\Pricing\ValueObjects\UsageInFlight;
use Carbon\CarbonInterface;

/**
 * A coupon given to named members is theirs alone; one given to a company
 * is for its members. Both may be set: then a member must be both.
 */
class MemberIsInAudience implements CouponRule
{
    public function rejection(Coupon $coupon, User $member, PricingTarget $target, UsageInFlight $inFlight, CarbonInterface $at): ?CouponRejection
    {
        if ($coupon->users()->exists() && ! $coupon->users()->whereKey($member->id)->exists()) {
            return CouponRejection::NotEligible;
        }

        if ($coupon->company_id !== null && ! $member->companies()->whereKey($coupon->company_id)->exists()) {
            return CouponRejection::NotEligible;
        }

        return null;
    }
}
