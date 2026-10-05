<?php

namespace App\Domain\Pricing\Rules;

use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Enums\CouponRejection;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\ValueObjects\PricingTarget;
use App\Domain\Pricing\ValueObjects\UsageInFlight;
use Carbon\CarbonInterface;

/**
 * Switched on, and inside its dates: from `starts_at` up to, not including,
 * `ends_at`. A switched-off coupon reads as unknown, so codes cannot be
 * probed.
 */
class IsWithinValidity implements CouponRule
{
    public function rejection(Coupon $coupon, User $member, PricingTarget $target, UsageInFlight $inFlight, CarbonInterface $at): ?CouponRejection
    {
        return match (true) {
            ! $coupon->is_active => CouponRejection::NotFound,
            $coupon->starts_at !== null && $at->lessThan($coupon->starts_at) => CouponRejection::NotStarted,
            $coupon->ends_at !== null && $at->greaterThanOrEqualTo($coupon->ends_at) => CouponRejection::Expired,
            default => null,
        };
    }
}
