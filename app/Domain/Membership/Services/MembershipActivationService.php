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
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
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
     * Whether the plan's seats would fit if it were activated now for this
     * owner — the check activate() makes, made ahead, for a sale paid before
     * it is activated (a card: the gateway's page comes first). It takes no
     * lock, so activate() still decides; this only spares the member paying
     * for a plan that cannot be had.
     *
     * @throws SeatPoolExhaustedException
     */
    public function assertSeatsFree(Plan $plan, OwnerType $ownerType, int $ownerId, int $seats = 1): void
    {
        $branchId = $this->branches->branchFor($ownerType, $ownerId);
        $branch = $branchId === null ? null : Branch::query()->find($branchId);

        if ($branch !== null) {
            $this->assertSeatsIn($branch, $plan, $seats, now(), now()->addDays($plan->duration_days));
        }
    }

    /**
     * When the plan's seats are expected to be free for its whole period, for
     * an owner it was just refused to (CoworkingPoolService::earliestSubscriptionStart),
     * looking a year ahead; null when not within it.
     */
    public function availableFrom(Plan $plan, OwnerType $ownerType, int $ownerId, int $seats = 1): ?CarbonImmutable
    {
        $branchId = $this->branches->branchFor($ownerType, $ownerId);
        $branch = $branchId === null ? null : Branch::query()->find($branchId);

        return $branch === null ? null : $this->pool->earliestSubscriptionStart($branch, $plan, $seats, now(), now()->addYear());
    }

    /** @throws SeatPoolExhaustedException */
    private function assertSeatsIn(Branch $branch, Plan $plan, int $seats, CarbonInterface $periodStart, CarbonInterface $periodEnd): void
    {
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
     * `$auditProperties` are added to the audit entry — how a sale paid
     * elsewhere (PaymentService) names the payment that paid for it.
     *
     * `$startsAt` starts the period later (a renewal made in advance);
     * `$attributes` only add the `auto_renew` and `renewed_from_id`
     * columns to the new row; any other key is ignored.
     *
     * @param  list<int>  $deskIds
     * @param  array<string, mixed>  $auditProperties
     * @param  array<string, mixed>  $attributes
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
        array $auditProperties = [],
        ?CarbonInterface $startsAt = null,
        array $attributes = [],
    ): Membership {
        if ($ownerType === OwnerType::User && $seats !== 1) {
            throw new \InvalidArgumentException('A member\'s own subscription holds exactly one seat.');
        }

        return DB::transaction(function () use ($plan, $ownerType, $ownerId, $charge, $auditAction, $branchId, $seats, $overrideCapacity, $deskIds, $auditProperties, $startsAt, $attributes) {
            $branchId ??= $this->branches->branchFor($ownerType, $ownerId);
            $periodStart = $startsAt !== null ? $startsAt->copy() : now();
            $periodEnd = $periodStart->copy()->addDays($plan->duration_days);

            // Before the wallet is touched, and on the branch row every other
            // seat decision locks, so two sales cannot both take the last seat.
            $branch = $branchId === null ? null : Branch::query()->whereKey($branchId)->lockForUpdate()->first();

            if ($branch !== null && ! $overrideCapacity) {
                $this->assertSeatsIn($branch, $plan, $seats, $periodStart, $periodEnd);
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
                ...Arr::only($attributes, ['auto_renew', 'renewed_from_id']),
            ]);

            if ($deskIds !== []) {
                $this->desks->assign($membership->setRelation('plan', $plan), $deskIds);
            } elseif ($charge && $plan->isDedicatedDesk()) {
                // Bought by the member — nobody has picked their desk yet.
                // After commit, so a sale that rolls back tells nobody.
                DB::afterCommit(fn () => $this->desks->notifyStaffDeskNeeded($membership->setRelation('plan', $plan)));
            }

            // A renewal made in advance holds its allowance until it starts
            // (grantDueBalances, from the renewal run), or the company would
            // spend next period's allowance in this one.
            if (! $periodStart->isFuture()) {
                $this->grantPlanBalances($plan, $membership, $wallet);
                $membership->forceFill(['balances_granted_at' => now()])->save();
            }

            $this->logSensitiveAction($auditAction ?? 'membership_purchased', $membership, array_merge([
                'plan_id' => $plan->id,
                'owner_type' => $ownerType->value,
                'owner_id' => $ownerId,
                'price_points' => (int) $plan->price_points,
                'charged' => $charge,
                'branch_id' => $branchId,
                'seats' => $seats,
                'capacity_overridden' => $overrideCapacity,
            ], $auditProperties));

            return $membership;
        });
    }

    /**
     * Grants a started membership's plan balances once (renewals made in
     * advance). False when it has not started or was already granted.
     */
    public function grantDueBalances(Membership $membership): bool
    {
        return DB::transaction(function () use ($membership) {
            $locked = Membership::query()->with('plan.balances')->whereKey($membership->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->balances_granted_at !== null || $locked->current_period_start->isFuture()) {
                return false;
            }

            $this->grantPlanBalances($locked->plan, $locked, $this->wallets->walletFor($locked->owner_type, (int) $locked->owner_id));
            $locked->forceFill(['balances_granted_at' => now()])->save();

            return true;
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
