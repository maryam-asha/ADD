<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Models\WalletTransaction;
use App\Domain\Membership\Services\WalletHistoryService;
use App\Domain\Membership\Services\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\ListWalletTransactionsRequest;
use App\Support\Presenters\PriceLabelPresenter;
use App\Support\Presenters\WalletTransactionPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The member's own wallet: its balances, its history, and one row of it.
 * Topping it up is POST member/wallet/top-ups (TopUpController).
 *
 * `options()` is the read side of the hybrid wallet-routing decision
 * (docs/decisions/wallet-points-categorization.md, "Routing for a user with
 * both a personal wallet and a company membership") — reports every
 * currently-spendable wallet for a category so the caller can present an
 * explicit choice when there's more than one. No spend happens here.
 */
class WalletController extends Controller
{
    /**
     * The personal wallet's balance in every category, General first — the
     * one the app leads with, since it pays for anything. A member with no
     * wallet yet has nothing in it, not a 404.
     */
    public function show(Request $request, WalletService $wallets, PriceLabelPresenter $prices): JsonResponse
    {
        $member = $request->user();

        $wallet = Wallet::query()
            ->where('owner_type', OwnerType::User)
            ->where('owner_id', $member->id)
            ->first();

        $balances = $wallet === null
            ? array_fill_keys(array_map(fn (WalletTransactionCategory $c) => $c->value, WalletTransactionCategory::cases()), 0)
            : $wallets->balances($wallet, $member);

        return response()->json([
            'data' => [
                'balance' => $prices->total($balances[WalletTransactionCategory::General->value], $member),
                'categories' => collect($balances)
                    ->map(fn (int $points, string $category) => [
                        'category' => $category,
                        'label' => __("api.wallet.categories.{$category}"),
                    ] + $prices->total($points, $member))
                    ->values(),
            ],
        ]);
    }

    /**
     * The personal wallet's rows, newest first, optionally narrowed to
     * credits or debits, one category, or a range of days.
     */
    public function transactions(
        ListWalletTransactionsRequest $request,
        WalletHistoryService $history,
        WalletTransactionPresenter $presenter,
    ): JsonResponse {
        $member = $request->user();

        $paginator = $history->paginate(
            $member,
            $request->safe()->only(['type', 'category', 'from', 'to']),
            (int) ($request->validated('per_page') ?? 20),
        );

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(fn (WalletTransaction $transaction) => $presenter->item($transaction, $member))
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * One row as a receipt: what it was, how it was paid, and the balance
     * either side of it.
     */
    public function transaction(
        Request $request,
        WalletTransaction $transaction,
        WalletHistoryService $history,
        WalletService $wallets,
        WalletTransactionPresenter $presenter,
    ): JsonResponse {
        $member = $request->user();

        // 404 rather than 403, so this cannot be used to probe which rows
        // exist in other wallets.
        abort_unless($history->isOwnedBy($transaction, $member), 404);

        $history->load($transaction);

        return response()->json([
            'data' => $presenter->detail($transaction, $member, $wallets->balanceAfter($transaction, $member)),
        ]);
    }

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
