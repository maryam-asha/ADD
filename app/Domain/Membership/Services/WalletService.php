<?php

namespace App\Domain\Membership\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Exceptions\InsufficientBalanceException;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Models\WalletTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Implements the read-time balance computation and debit-resolution
 * algorithm from docs/decisions/phase-3-membership-plan-wallet-mechanics.md
 * and docs/decisions/wallet-points-categorization.md. No stored balance —
 * every "balance" here is a sum of `wallet_transactions.amount` computed on
 * the spot via bcmath, never the DB query builder's float-coercing `sum()`.
 */
class WalletService
{
    /**
     * Does not create — provisioning happens elsewhere (CompanyController::store,
     * MemberAuthController::verifyOtp).
     */
    public function walletFor(OwnerType $ownerType, int $ownerId): Wallet
    {
        return Wallet::where('owner_type', $ownerType)->where('owner_id', $ownerId)->firstOrFail();
    }

    /**
     * General never expires (the rollover rule) — always `expires_at = null`.
     */
    public function creditGeneral(
        Wallet $wallet,
        string $amount,
        WalletTransactionSource $source,
        ?string $description = null
    ): WalletTransaction {
        return $wallet->transactions()->create([
            'amount' => $amount,
            'category' => WalletTransactionCategory::General,
            'restricted_space_id' => null,
            'source' => $source,
            'expires_at' => null,
            'description' => $description,
        ]);
    }

    /**
     * Every categorized/restricted balance must expire (no-rollover rule) —
     * `$expiresAt` is required, not derived, since there is no default to
     * compute it from.
     */
    public function creditCategorized(
        Wallet $wallet,
        WalletTransactionCategory $category,
        string $amount,
        WalletTransactionSource $source,
        \DateTimeInterface $expiresAt,
        ?int $restrictedSpaceId = null,
        array $allowedUserIds = [],
        ?string $description = null
    ): WalletTransaction {
        if ($category === WalletTransactionCategory::General) {
            throw new \InvalidArgumentException(
                'creditCategorized() cannot be used for the General category — use creditGeneral() instead.'
            );
        }

        return DB::transaction(function () use (
            $wallet,
            $category,
            $amount,
            $source,
            $expiresAt,
            $restrictedSpaceId,
            $allowedUserIds,
            $description
        ) {
            $transaction = $wallet->transactions()->create([
                'amount' => $amount,
                'category' => $category,
                'restricted_space_id' => $restrictedSpaceId,
                'source' => $source,
                'expires_at' => $expiresAt,
                'description' => $description,
            ]);

            if (! empty($allowedUserIds)) {
                $transaction->allowedUsers()->attach($allowedUserIds);
            }

            return $transaction;
        });
    }

    /**
     * Unconditional pool-level debit against `category = General` only.
     * Locks the general credit/debit rows being summed so two concurrent
     * debits can't both read the same balance and both succeed when only
     * one should.
     */
    public function debitGeneral(Wallet $wallet, string $amount, ?string $description = null): WalletTransaction
    {
        return DB::transaction(function () use ($wallet, $amount, $description) {
            $balance = $this->lockedGeneralBalance($wallet);

            if (bccomp($balance, $amount, 2) < 0) {
                throw new InsufficientBalanceException(sprintf(
                    'Wallet %d has insufficient general balance: needs %s, has %s (short %s).',
                    $wallet->id,
                    $amount,
                    $balance,
                    bcsub($amount, $balance, 2)
                ));
            }

            return WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'amount' => bcsub('0', $amount, 2),
                'category' => WalletTransactionCategory::General,
                'restricted_space_id' => null,
                'source' => WalletTransactionSource::TopUp,
                'expires_at' => null,
                'description' => $description,
            ]);
        });
    }

    /**
     * The debit-resolution algorithm
     * (docs/decisions/phase-3-membership-plan-wallet-mechanics.md). For a
     * real category, resolves against distinct restriction-signature
     * sub-pools within that category, soonest-expiry-first; if the category
     * pool can't fully cover the amount, nothing category-side is
     * committed and the whole request falls back to `debitGeneral()`.
     *
     * @return list<WalletTransaction>
     */
    public function debit(
        Wallet $wallet,
        User $spendingUser,
        WalletTransactionCategory $category,
        string $amount,
        ?string $description = null
    ): array {
        if ($category === WalletTransactionCategory::General) {
            return [$this->debitGeneral($wallet, $amount, $description)];
        }

        DB::beginTransaction();

        try {
            [$created, $stillNeeded] = $this->attemptCategoryDebit($wallet, $spendingUser, $category, $amount, $description);
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        if (bccomp($stillNeeded, '0.00', 2) <= 0) {
            DB::commit();

            return $created;
        }

        // The category pool couldn't fully cover the request — nothing
        // category-side may be partially committed. Roll it all back and
        // fall back to general for the original full amount.
        DB::rollBack();

        return [$this->debitGeneral($wallet, $amount, $description)];
    }

    /**
     * For a user's own personal wallet and every company wallet they
     * belong to, the usable balance for `$category` (falling back to
     * general per wallet). Read-only, plain filtered sums — no locking, no
     * signature/consumption logic.
     *
     * @return list<array{wallet_id: int, owner_type: string, owner_id: int, owner_label: string, category: string, category_balance: string, general_balance: string, usable_balance: string}>
     */
    public function spendOptions(User $user, WalletTransactionCategory $category): array
    {
        $options = [];

        $personalWallet = Wallet::where('owner_type', OwnerType::User)->where('owner_id', $user->id)->first();

        if ($personalWallet !== null) {
            $option = $this->buildSpendOption($personalWallet, $user, $category, OwnerType::User, 'Personal');

            if ($option !== null) {
                $options[] = $option;
            }
        }

        foreach ($user->companies as $company) {
            $companyWallet = Wallet::where('owner_type', OwnerType::Company)->where('owner_id', $company->id)->first();

            if ($companyWallet === null) {
                continue;
            }

            $option = $this->buildSpendOption($companyWallet, $user, $category, OwnerType::Company, $company->legal_name);

            if ($option !== null) {
                $options[] = $option;
            }
        }

        return $options;
    }

    /**
     * Sums the wallet's non-expired General transactions with
     * `lockForUpdate()` — the row locking `debitGeneral()` uses to prevent
     * two concurrent debits from both reading the same balance.
     */
    private function lockedGeneralBalance(Wallet $wallet): string
    {
        $transactions = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('category', WalletTransactionCategory::General)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->lockForUpdate()
            ->get();

        return $this->sumAmounts($transactions);
    }

    /**
     * @return array{0: list<WalletTransaction>, 1: string} [createdDebitRows, stillNeeded]
     */
    private function attemptCategoryDebit(
        Wallet $wallet,
        User $spendingUser,
        WalletTransactionCategory $category,
        string $amount,
        ?string $description
    ): array {
        $credits = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('category', $category)
            ->where('amount', '>', 0)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->with('allowedUsers')
            ->lockForUpdate()
            ->get();

        $eligibleCredits = $credits->filter(
            fn (WalletTransaction $credit) => $credit->allowedUsers->isEmpty()
                || $credit->allowedUsers->contains('id', $spendingUser->id)
        );

        $debits = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('category', $category)
            ->where('amount', '<', 0)
            ->with('allowedUsers')
            ->lockForUpdate()
            ->get();

        $debitedBySignature = [];

        foreach ($debits as $debit) {
            $signature = $this->signatureFor($debit->allowedUsers);
            $debitedBySignature[$signature] = bcadd($debitedBySignature[$signature] ?? '0.00', (string) $debit->amount, 2);
        }

        // Credits sharing the same restriction signature are one fungible
        // pool, not independent balances — grouping them here is what makes
        // "remaining = own amount + same-signature debits" exact rather than
        // (as a previous version of this method got wrong) applying the
        // *entire* signature's historical debit total to *each* credit in
        // that signature independently, which under-counts by (n-1) times
        // the debited amount whenever two or more credits share a signature.
        $groups = [];

        foreach ($eligibleCredits as $credit) {
            $signature = $this->signatureFor($credit->allowedUsers);

            if (! isset($groups[$signature])) {
                $groups[$signature] = [
                    'amountSum' => '0.00',
                    'soonestExpiresAt' => null,
                    'allowedUserIds' => $credit->allowedUsers->pluck('id')->all(),
                ];
            }

            $groups[$signature]['amountSum'] = bcadd($groups[$signature]['amountSum'], (string) $credit->amount, 2);

            if ($credit->expires_at !== null
                && ($groups[$signature]['soonestExpiresAt'] === null || $credit->expires_at->lt($groups[$signature]['soonestExpiresAt']))) {
                $groups[$signature]['soonestExpiresAt'] = $credit->expires_at;
            }
        }

        $candidates = [];

        foreach ($groups as $signature => $group) {
            $remaining = bcadd($group['amountSum'], $debitedBySignature[$signature] ?? '0.00', 2);

            if (bccomp($remaining, '0.00', 2) <= 0) {
                continue;
            }

            $candidates[] = [
                'signature' => $signature,
                'remaining' => $remaining,
                'expiresAt' => $group['soonestExpiresAt'],
                'allowedUserIds' => $group['allowedUserIds'],
            ];
        }

        usort($candidates, function (array $a, array $b) {
            $aExpires = $a['expiresAt'];
            $bExpires = $b['expiresAt'];

            $cmp = match (true) {
                $aExpires === null && $bExpires === null => 0,
                $aExpires === null => 1,
                $bExpires === null => -1,
                default => $aExpires->getTimestamp() <=> $bExpires->getTimestamp(),
            };

            return $cmp !== 0 ? $cmp : strcmp($a['signature'], $b['signature']);
        });

        $created = [];
        $stillNeeded = $amount;

        foreach ($candidates as $candidate) {
            if (bccomp($stillNeeded, '0.00', 2) <= 0) {
                break;
            }

            $consume = bccomp($candidate['remaining'], $stillNeeded, 2) < 0
                ? $candidate['remaining']
                : $stillNeeded;

            $debitRow = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'amount' => bcsub('0', $consume, 2),
                'category' => $category,
                'restricted_space_id' => null,
                'source' => WalletTransactionSource::TopUp,
                'expires_at' => null,
                'description' => $description,
            ]);

            if (! empty($candidate['allowedUserIds'])) {
                $debitRow->allowedUsers()->attach($candidate['allowedUserIds']);
            }

            $created[] = $debitRow;
            $stillNeeded = bcsub($stillNeeded, $consume, 2);
        }

        return [$created, $stillNeeded];
    }

    private function buildSpendOption(
        Wallet $wallet,
        User $user,
        WalletTransactionCategory $category,
        OwnerType $ownerType,
        string $ownerLabel
    ): ?array {
        $categoryBalance = $this->eligibleBalance($wallet, $user, $category);
        $generalBalance = $this->eligibleBalance($wallet, $user, WalletTransactionCategory::General);

        $usableBalance = bccomp($categoryBalance, '0.00', 2) > 0 ? $categoryBalance : $generalBalance;

        if (bccomp($usableBalance, '0.00', 2) <= 0) {
            return null;
        }

        return [
            'wallet_id' => $wallet->id,
            'owner_type' => $ownerType->value,
            'owner_id' => $wallet->owner_id,
            'owner_label' => $ownerLabel,
            'category' => $category->value,
            'category_balance' => $categoryBalance,
            'general_balance' => $generalBalance,
            'usable_balance' => $usableBalance,
        ];
    }

    /**
     * Plain filtered sum for a read — no locking. Sums every non-expired
     * transaction (credit and debit alike) in `$category` that `$user` is
     * eligible for, which nets correctly against exact-signature debits
     * created by `debit()`.
     */
    private function eligibleBalance(Wallet $wallet, User $user, WalletTransactionCategory $category): string
    {
        $transactions = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('category', $category)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->with('allowedUsers')
            ->get();

        $eligible = $transactions->filter(
            fn (WalletTransaction $transaction) => $transaction->allowedUsers->isEmpty()
                || $transaction->allowedUsers->contains('id', $user->id)
        );

        return $this->sumAmounts($eligible);
    }

    /**
     * @param  Collection<int, WalletTransaction>  $transactions
     */
    private function sumAmounts(Collection $transactions): string
    {
        $total = '0.00';

        foreach ($transactions as $transaction) {
            $total = bcadd($total, (string) $transaction->amount, 2);
        }

        return $total;
    }

    /**
     * The sorted set of a transaction's allowed-user ids, as a comparable
     * string key — empty string for unrestricted.
     *
     * @param  Collection<int, User>  $allowedUsers
     */
    private function signatureFor(Collection $allowedUsers): string
    {
        return $allowedUsers->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->implode(',');
    }
}
