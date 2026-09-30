<?php

namespace App\Domain\Membership\Services;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Booking\Services\CoworkingPoolService;
use App\Domain\Booking\ValueObjects\PoolDemand;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Exceptions\SeatPoolExhaustedException;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * The one place a Membership is created, for both ways it can happen:
 *
 *  - a member buys a plan themselves, which debits their (or their
 *    company's) wallet general balance first — the original flow, unchanged;
 *  - operations activates a plan for someone from the dashboard, which skips
 *    the debit entirely because the arrangement was paid for outside the
 *    app (docs/decisions/plan-access-windows.md). This is how private plans
 *    reach the company staff they were negotiated for.
 *
 * The plan-balance granting below must run on BOTH paths — an activated
 * member with no granted balances would hold a subscription with nothing in
 * it — which is exactly why this was extracted rather than duplicated.
 *
 * `plan_balances` is the ONLY grant path. The old `plans.included_hours`
 * fallback (used when a plan carried no balances) is gone: it counted hours
 * but credited points 1:1, which its own TODO admitted was wrong. Natural-unit
 * quotas belong in `plan_resource_grants` instead, where the unit is explicit.
 */
class MembershipActivationService
{
    use LogsSensitiveActions;

    public function __construct(
        private readonly WalletService $wallets,
        private readonly MembershipBranchResolver $branches,
        private readonly CoworkingPoolService $pool,
        private readonly DedicatedDeskService $desks,
    ) {}

    /**
     * Throws InsufficientBalanceException when $charge is true and the wallet
     * cannot cover the plan's price, and SeatPoolExhaustedException when the
     * subscription's seats do not fit in its branch's co-working pool — the
     * caller decides how to surface either.
     *
     * The subscription holds `$seats` seats in its branch's pool for its whole
     * period, while its plan is open (docs/decisions/coworking-seat-pool.md).
     * The branch, when not given, is the owner's (MembershipBranchResolver).
     * Only a member of staff selling it by arrangement may
     * `$overrideCapacity`; the override is recorded in the audit entry.
     *
     * `$deskIds` ties a Dedicated Desk subscription to its desks in the same
     * transaction — DeskAssignmentRefusedException undoes the whole sale.
     *
     * @param  list<int>  $deskIds
     */
    public function activate(
        Plan $plan,
        OwnerType $ownerType,
        int $ownerId,
        bool $charge = true,
        ?string $auditAction = null,
        ?int $branchId = null,
        int $seats = 1,
        bool $overrideCapacity = false,
        array $deskIds = [],
    ): Membership {
        if ($ownerType === OwnerType::User && $seats !== 1) {
            throw new \InvalidArgumentException('A member\'s own subscription holds exactly one seat.');
        }

        return DB::transaction(function () use ($plan, $ownerType, $ownerId, $charge, $auditAction, $branchId, $seats, $overrideCapacity, $deskIds) {
            $branchId ??= $this->branches->branchFor($ownerType, $ownerId);
            $periodStart = now();
            $periodEnd = now()->addDays($plan->duration_days);

            // Before the wallet is touched, and on the branch row every other
            // seat decision locks, so two sales cannot both take the last seat.
            $branch = $branchId === null ? null : Branch::query()->whereKey($branchId)->lockForUpdate()->first();

            if ($branch !== null && ! $overrideCapacity) {
                $timeline = $this->pool->timeline(
                    $branch,
                    $periodStart,
                    $periodEnd,
                    PoolDemand::subscription($plan, $seats, $periodStart, $periodEnd),
                );

                if ($timeline->minFree() < 0) {
                    throw new SeatPoolExhaustedException($timeline->firstShortfall());
                }
            }

            $wallet = $this->wallets->walletFor($ownerType, $ownerId);

            if ($charge) {
                $this->wallets->debitGeneral($wallet, (int) $plan->price_points, "Membership purchase: plan #{$plan->id}");
            }

            $membership = Membership::create([
                'plan_id' => $plan->id,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'branch_id' => $branchId,
                'seats' => $seats,
                'status' => MembershipStatus::Active,
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
            ]);

            if ($deskIds !== []) {
                $this->desks->assign($membership->setRelation('plan', $plan), $deskIds);
            }

            $this->grantPlanBalances($plan, $membership, $wallet);

            $this->logSensitiveAction($auditAction ?? 'membership_purchased', $membership, [
                'plan_id' => $plan->id,
                'owner_type' => $ownerType->value,
                'owner_id' => $ownerId,
                'price_points' => (int) $plan->price_points,
                'charged' => $charge,
                'branch_id' => $branchId,
                'seats' => $seats,
                'capacity_overridden' => $overrideCapacity,
            ]);

            return $membership;
        });
    }

    private function grantPlanBalances(Plan $plan, Membership $membership, Wallet $wallet): void
    {
        foreach ($plan->balances as $grant) {
            $desc = $grant->translate('description')
                ?? "{$grant->category->value} allowance for membership #{$membership->id}";

            if ($grant->category === WalletTransactionCategory::General) {
                $this->wallets->creditGeneral($wallet, (int) $grant->amount_points, WalletTransactionSource::SubscriptionGrant, $desc);
            } else {
                $this->wallets->creditCategorized(
                    $wallet,
                    $grant->category,
                    (int) $grant->amount_points,
                    WalletTransactionSource::SubscriptionGrant,
                    $membership->current_period_end,
                    $grant->restricted_space_id,
                    [],
                    $desc
                );
            }
        }
    }
}
