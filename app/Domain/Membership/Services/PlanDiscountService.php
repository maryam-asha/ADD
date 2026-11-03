<?php

namespace App\Domain\Membership\Services;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\PlanDiscount;
use App\Domain\Membership\Models\PlanDiscountRedemption;
use Carbon\CarbonInterface;

/**
 * Pure discount resolution and ledger-writing — no wallet knowledge, so it
 * stays composable with whatever pays the resulting (possibly discounted)
 * point amount. docs/decisions/plan-discounts-design.md.
 */
class PlanDiscountService
{
    public function __construct(private readonly PlanAllowanceService $allowances) {}

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
     * Uses are counted for `$user` alone when given — an employee of a
     * company plan has the limit to themselves — and only within the cycle
     * containing `$at` (now by default): the whole period, or each month
     * from the subscription's start (product owner, 2026-11-02).
     *
     * @param  array<int, int>  $consumedInBatch
     */
    public function resolveFor(Membership $membership, Space $space, array $consumedInBatch = [], ?User $user = null, ?CarbonInterface $at = null): ?PlanDiscount
    {
        $plan = $membership->plan;
        $at ??= now();

        // A plan's discount is for its own period: none on time after it ends.
        if ($plan === null
            || $at->lessThan($membership->current_period_start)
            || $at->greaterThanOrEqualTo($membership->current_period_end)) {
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
            if ($this->hasRemainingUsage($discount, $membership, $consumedInBatch, $user, $at)) {
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
     * Records that `$discount` priced `$for` — a booking, paid now or
     * later (the discounted price is its price either way), or a walk-in at
     * its closure — for the person it belongs to. Must be called inside the
     * transaction that prices it.
     */
    public function redeem(PlanDiscount $discount, Membership $membership, Booking|WalkinSession $for, int $pointsDiscounted): void
    {
        PlanDiscountRedemption::create([
            'plan_discount_id' => $discount->id,
            'membership_id' => $membership->id,
            'user_id' => $for->user_id,
            'booking_id' => $for instanceof Booking ? $for->id : null,
            'walkin_session_id' => $for instanceof WalkinSession ? $for->id : null,
            // Counted in the cycle of what it priced, not of today.
            'counted_at' => $for instanceof Booking ? $for->start_at : $for->checked_in_at,
            'points_discounted' => $pointsDiscounted,
        ]);
    }

    public function voidFor(Booking|WalkinSession $for): void
    {
        PlanDiscountRedemption::query()
            ->where($for instanceof Booking ? 'booking_id' : 'walkin_session_id', $for->id)
            ->delete();
    }

    /**
     * @param  array<int, int>  $consumedInBatch
     */
    private function hasRemainingUsage(PlanDiscount $discount, Membership $membership, array $consumedInBatch, ?User $user, CarbonInterface $at): bool
    {
        if ($discount->usage_limit === null) {
            return true;
        }

        [$cycleStart, $cycleEnd] = $this->allowances->cycleFor($membership, $at);

        $used = $discount->redemptions()
            ->where('membership_id', $membership->id)
            ->when($user !== null, fn ($query) => $query->where('user_id', $user->id))
            ->where('counted_at', '>=', $cycleStart)
            ->where('counted_at', '<', $cycleEnd)
            ->count()
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
