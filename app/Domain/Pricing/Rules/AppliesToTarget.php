<?php

namespace App\Domain\Pricing\Rules;

use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Enums\CouponRejection;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Models\CouponTarget;
use App\Domain\Pricing\ValueObjects\PricingTarget;
use App\Domain\Pricing\ValueObjects\UsageInFlight;
use Carbon\CarbonInterface;

/**
 * A coupon with no targets applies to everything it can be spent on; one
 * with targets applies where one of them names this purpose and, when it
 * names an item, this item.
 */
class AppliesToTarget implements CouponRule
{
    public function rejection(Coupon $coupon, User $member, ?PricingTarget $target, UsageInFlight $inFlight, CarbonInterface $at): ?CouponRejection
    {
        if ($target === null) {
            return null;
        }

        $targets = $coupon->relationLoaded('targets') ? $coupon->targets : $coupon->targets()->get();

        if ($targets->isEmpty()) {
            return null;
        }

        return $targets->contains(fn (CouponTarget $t) => $this->matches($t, $target)) ? null : CouponRejection::NotApplicable;
    }

    private function matches(CouponTarget $t, PricingTarget $target): bool
    {
        return $t->purpose === $target->purpose
            && ($t->plan_id === null || $t->plan_id === $target->planId)
            && ($t->space_id === null || $t->space_id === $target->spaceId)
            && ($t->space_type === null || $t->space_type === $target->spaceType)
            && ($t->event_id === null || $t->event_id === $target->eventId);
    }
}
