<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Identity\Models\Company;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Exceptions\InsufficientBalanceException;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Services\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\StoreMembershipRequest;
use App\Http\Resources\MembershipResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/architecture/2026-08-08-backend-build-plan.md ("Phase 3 — Membership")
 * — buying a plan debits the buyer's (or their company's) wallet general
 * balance and creates the Membership row in one transaction. Same
 * "self-service checked via the policy in the controller" shape as
 * CompanyWalletAllocationController.
 */
class MembershipController extends Controller
{
    use LogsSensitiveActions;

    public function store(StoreMembershipRequest $request, WalletService $wallets): MembershipResource
    {
        $plan = Plan::findOrFail($request->validated('plan_id'));

        if ($request->validated('company_id')) {
            $company = Company::findOrFail($request->validated('company_id'));

            Gate::authorize('manageMembers', $company);

            $ownerType = OwnerType::Company;
            $ownerId = $company->id;
        } else {
            $ownerType = OwnerType::User;
            $ownerId = $request->user()->id;
        }

        try {
            $membership = DB::transaction(function () use ($wallets, $plan, $ownerType, $ownerId) {
                $wallet = $wallets->walletFor($ownerType, $ownerId);

                $wallets->debitGeneral($wallet, (int) $plan->price_points, "Membership purchase: plan #{$plan->id}");

                $membership = Membership::create([
                    'plan_id' => $plan->id,
                    'owner_type' => $ownerType,
                    'owner_id' => $ownerId,
                    'status' => MembershipStatus::Active,
                    'current_period_start' => now(),
                    'current_period_end' => now()->addDays($plan->duration_days),
                ]);

                if ($plan->balances()->exists()) {
                    foreach ($plan->balances as $grant) {
                        $desc = $grant->translate('description')
                            ?? "{$grant->category->value} allowance for membership #{$membership->id}";

                        if ($grant->category === WalletTransactionCategory::General) {
                            $wallets->creditGeneral($wallet, (int) $grant->amount_points, WalletTransactionSource::SubscriptionGrant, $desc);
                        } else {
                            $wallets->creditCategorized(
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
                } elseif (bccomp((string) $plan->included_hours, '0.00', 2) > 0) {
                    // TODO(spec-C): `included_hours` is an hours figure, not a
                    // point count, and was already being written into the ledger
                    // 1:1 before the ledger became point-denominated. Cast
                    // preserves exactly that pre-existing behaviour; deciding
                    // the real hours->points rule belongs with the pricing work.
                    $wallets->creditCategorized(
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

                $this->logSensitiveAction('membership_purchased', $membership, [
                    'plan_id' => $plan->id,
                    'owner_type' => $ownerType->value,
                    'owner_id' => $ownerId,
                    'price_points' => (int) $plan->price_points,
                ]);

                return $membership;
            });
        } catch (InsufficientBalanceException) {
            // abort() throws, so this never actually returns — keeps the
            // method's return type honestly `MembershipResource` while still
            // producing the same 422 shape as CompanyWalletAllocationController
            // (shouldRenderJsonWhen(true) in bootstrap/app.php turns this into
            // a plain JSON {"message": ...} response).
            abort(422, __('api.wallet.insufficient_balance_for_plan'));
        }

        return new MembershipResource($membership->load('plan'));
    }
}
