<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Identity\Models\Company;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Exceptions\InsufficientBalanceException;
use App\Domain\Membership\Services\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\StoreWalletAllocationRequest;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/decisions/wallet-points-categorization.md /
 * docs/decisions/phase-3-membership-plan-wallet-mechanics.md
 * ("Company-admin allocation is a reallocation, not new money") — a company
 * admin (CompanyPolicy::manageMembers) moves an amount from the company
 * wallet's general balance into a categorized/restricted grant for specific
 * employees. Net wallet balance is unchanged; only the category/restriction
 * tag on that amount changes.
 */
class CompanyWalletAllocationController extends Controller
{
    use LogsSensitiveActions;

    public function store(StoreWalletAllocationRequest $request, Company $company, WalletService $wallets): JsonResponse
    {
        Gate::authorize('manageMembers', $company);

        $wallet = $wallets->walletFor(OwnerType::Company, $company->id);

        try {
            $allocation = DB::transaction(function () use ($request, $company, $wallets, $wallet) {
                $wallets->debitGeneral($wallet, $request->validated('amount'), 'Allocation to company members');

                $allocation = $wallets->creditCategorized(
                    $wallet,
                    WalletTransactionCategory::from($request->validated('category')),
                    $request->validated('amount'),
                    WalletTransactionSource::CompanyAdminAllocation,
                    Carbon::parse($request->validated('expires_at')),
                    $request->validated('restricted_space_id'),
                    $request->validated('user_ids'),
                    $request->validated('description')
                );

                $this->logSensitiveAction('wallet_allocation_created', $allocation, [
                    'company_id' => $company->id,
                    'category' => $allocation->category->value,
                    'amount' => $request->validated('amount'),
                    'user_ids' => $request->validated('user_ids'),
                ]);

                return $allocation;
            });
        } catch (InsufficientBalanceException) {
            return response()->json(['message' => __('api.wallet.insufficient_balance')], 422);
        }

        return response()->json([
            'data' => [
                'id' => $allocation->id,
                'category' => $allocation->category->value,
                'amount' => (string) $allocation->amount,
                'restricted_space_id' => $allocation->restricted_space_id,
                'source' => $allocation->source->value,
                'expires_at' => $allocation->expires_at?->toIso8601String(),
                'allowed_user_ids' => $allocation->allowedUsers->pluck('id')->all(),
            ],
        ], 201);
    }
}
