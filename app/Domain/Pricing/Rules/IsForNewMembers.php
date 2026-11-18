<?php

namespace App\Domain\Pricing\Rules;

use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Enums\CouponRejection;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Services\FirstPurchasePolicy;
use App\Domain\Pricing\ValueObjects\PricingTarget;
use App\Domain\Pricing\ValueObjects\UsageInFlight;
use Carbon\CarbonInterface;

/** A new-members-only coupon, for someone who has not yet paid for anything. */
class IsForNewMembers implements CouponRule
{
    public function __construct(private readonly FirstPurchasePolicy $firstPurchase) {}

    public function rejection(Coupon $coupon, User $member, ?PricingTarget $target, UsageInFlight $inFlight, CarbonInterface $at): ?CouponRejection
    {
        return $coupon->new_members_only && ! $this->firstPurchase->isFirstPurchase($member)
            ? CouponRejection::NewMembersOnly
            : null;
    }
}
