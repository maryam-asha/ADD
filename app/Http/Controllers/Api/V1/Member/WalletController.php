<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Services\WalletService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Read side of the hybrid wallet-routing decision
 * (docs/decisions/wallet-points-categorization.md, "Routing for a user with
 * both a personal wallet and a company membership") — reports every
 * currently-spendable wallet for a category so the caller can present an
 * explicit choice when there's more than one. No spend happens here.
 */
class WalletController extends Controller
{
    public function options(Request $request, WalletService $wallets): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['required', Rule::enum(WalletTransactionCategory::class)],
        ]);

        $category = WalletTransactionCategory::from($validated['category']);

        return response()->json([
            'data' => $wallets->spendOptions($request->user(), $category),
        ]);
    }
}
