<?php

namespace App\Domain\Membership\Services;

use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Models\Space;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\PlanDiscount;
use App\Domain\Membership\Models\PlanDiscountRedemption;

/**
 * Pure discount resolution and ledger-writing — no wallet knowledge, so it
 * stays composable with whatever pays the resulting (possibly discounted)
 * point amount. docs/decisions/plan-discounts-design.md.
 */
class PlanDiscountService
{
    /**
     * The single discount (if any) that applies to a booking of `$space`
     * under `$membership`'s plan. No stacking — at most one row is ever
     * returned. Specificity order: an exact space match beats a matching
     * space_type, which beats a plan-wide (both null) discount. A
     * discount whose `usage_limit` is already exhausted for this
     * membership is never returned.
     *
     * `$consumedInBatch` lets a caller processing several not-yet-persisted
     * windows in one transaction (BookingBatchCreationService) report usage
     * it has already earmarked earlier in that same batch, since none of it
     * has a PlanDiscountRedemption row yet for the ledger count below to
     * see, keyed by `plan_discount_id`.
     *
     * @param  array<int, int>  $consumedInBatch
     */
    public function resolveFor(Membership $membership, Space $space, array $consumedInBatch = []): ?PlanDiscount
    {
        $plan = $membership->plan;

        if ($plan === null) {
            return null;
        }

        $discounts = $plan->relationLoaded('discounts') ? $plan->discounts : $plan->discounts()->get();

        $candidates = $discounts
            ->filter(fn (PlanDiscount $discount) => $discount->is_active)
            ->filter(function (PlanDiscount $discount) use ($space) {
                if ($discount->space_id !== null) {
                    return $discount->space_id === $space->id;
                }

                if ($discount->space_type !== null) {
                    return $discount->space_type === $space->space_type;
                }

                return true;
            })
            ->sort(fn (PlanDiscount $a, PlanDiscount $b) => $this->specificity($b) <=> $this->specificity($a))
            ->values();

        foreach ($candidates as $discount) {
            if ($this->hasRemainingUsage($discount, $membership, $consumedInBatch)) {
                return $discount;
            }
        }

        return null;
    }

    /**
     * `finalPoints = rawPoints - floor(rawPoints * percentage / 100)` —
     * the discount amount floors, matching AmountCalculator's "never
     * undercharge" rounding direction: the member never receives more
     * discount than the percentage strictly grants.
     */
    public function apply(int $rawPoints, PlanDiscount $discount): int
    {
        $product = bcmul((string) $rawPoints, (string) $discount->percentage);
        $discountAmount = bcdiv($product, '100', 0);

        return (int) bcsub((string) $rawPoints, $discountAmount);
    }

    /**
     * Records that `$discount` priced `$booking`, whether the booking is
     * paid now or later — the discounted price is its price either way.
     * Must be called inside the same DB transaction that creates the
     * booking.
     */
    public function redeem(PlanDiscount $discount, Membership $membership, Booking $booking, int $pointsDiscounted): void
    {
        PlanDiscountRedemption::create([
            'plan_discount_id' => $discount->id,
            'membership_id' => $membership->id,
            'booking_id' => $booking->id,
            'points_discounted' => $pointsDiscounted,
        ]);
    }

    public function voidFor(Booking $booking): void
    {
        PlanDiscountRedemption::where('booking_id', $booking->id)->delete();
    }

    /**
     * @param  array<int, int>  $consumedInBatch
     */
    private function hasRemainingUsage(PlanDiscount $discount, Membership $membership, array $consumedInBatch = []): bool
    {
        if ($discount->usage_limit === null) {
            return true;
        }

        $used = $discount->redemptions()->where('membership_id', $membership->id)->count()
            + ($consumedInBatch[$discount->id] ?? 0);

        return $used < $discount->usage_limit;
    }

    private function specificity(PlanDiscount $discount): int
    {
        return match (true) {
            $discount->space_id !== null => 2,
            $discount->space_type !== null => 1,
            default => 0,
        };
    }
}
