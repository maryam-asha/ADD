<?php

namespace App\Domain\Membership\Services;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
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
 */
class MembershipActivationService
{
    use LogsSensitiveActions;

    public function __construct(private readonly WalletService $wallets) {}

    /**
     * Throws InsufficientBalanceException when $charge is true and the wallet
     * cannot cover the plan's price; the caller decides how to surface that.
     */
    public function activate(
        Plan $plan,
        OwnerType $ownerType,
        int $ownerId,
        bool $charge = true,
        ?string $auditAction = null
    ): Membership {
        return DB::transaction(function () use ($plan, $ownerType, $ownerId, $charge, $auditAction) {
            $wallet = $this->wallets->walletFor($ownerType, $ownerId);

            if ($charge) {
                $this->wallets->debitGeneral($wallet, (int) $plan->price_points, "Membership purchase: plan #{$plan->id}");
            }

            $membership = Membership::create([
                'plan_id' => $plan->id,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'status' => MembershipStatus::Active,
                'current_period_start' => now(),
                'current_period_end' => now()->addDays($plan->duration_days),
            ]);

            $this->grantPlanBalances($plan, $membership, $wallet);

            $this->logSensitiveAction($auditAction ?? 'membership_purchased', $membership, [
                'plan_id' => $plan->id,
                'owner_type' => $ownerType->value,
                'owner_id' => $ownerId,
                'price_points' => (int) $plan->price_points,
                'charged' => $charge,
            ]);

            return $membership;
        });
    }

    private function grantPlanBalances(Plan $plan, Membership $membership, Wallet $wallet): void
    {
        if ($plan->balances()->exists()) {
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

            return;
        }

        if (bccomp((string) $plan->included_hours, '0.00', 2) > 0) {
            // TODO(spec-C): `included_hours` is an hours figure, not a
            // point count, and was already being written into the ledger
            // 1:1 before the ledger became point-denominated. Cast
            // preserves exactly that pre-existing behaviour; deciding
            // the real hours->points rule belongs with the pricing work.
            $this->wallets->creditCategorized(
                $wallet,
                WalletTransactionCategory::SpaceSpecific,
                (int) $plan->included_hours,
                WalletTransactionSource::SubscriptionGrant,
                $membership->current_period_end,
                null,
                [],
                "Included hours for membership #{$membership->id}"
            );
        }
    }
}
