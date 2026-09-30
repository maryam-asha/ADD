<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Enums\CashRegisterSessionStatus;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Models\CashRegisterSession;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\WalletTransaction;
use Illuminate\Validation\ValidationException;

class CashRegisterService
{
    /**
     * Open a new cash register shift session for the staff member.
     */
    public function openSession(User $user, int $branchId, string $openingBalance = '0.00', ?string $notes = null): CashRegisterSession
    {
        $existing = $this->currentSession($user);

        if ($existing) {
            throw ValidationException::withMessages([
                'session' => [__('api.finance.cash_register_already_open')],
            ]);
        }

        return CashRegisterSession::create([
            'user_id' => $user->id,
            'branch_id' => $branchId,
            'opened_at' => now(),
            'opening_balance' => $openingBalance,
            'status' => CashRegisterSessionStatus::Open,
            'notes' => $notes,
        ]);
    }

    /**
     * Close the active shift session and reconcile counted cash against expected cash.
     */
    public function closeSession(CashRegisterSession $session, string $closingBalance, ?string $notes = null): CashRegisterSession
    {
        if (! $session->isOpen()) {
            throw ValidationException::withMessages([
                'session' => [__('api.finance.cash_register_already_closed')],
            ]);
        }

        $now = now();

        // The cash this operator took during the shift: every ledger credit
        // they recorded as cash — top-ups and reception settlements alike —
        // summed as the MONEY handed over (`payment_amount`), never as the
        // points it bought. The session has no currency of its own, so it is
        // counted in the base currency: money taken in another currency is
        // converted at the rate stored on its row when it was taken.
        $cashCollected = WalletTransaction::query()
            ->where('performed_by_user_id', $session->user_id)
            ->where('payment_method', PaymentMethod::Cash->value)
            ->whereNotNull('payment_amount')
            ->whereBetween('created_at', [$session->opened_at, $now])
            ->get()
            ->reduce(function (string $carry, WalletTransaction $tx) {
                $money = (string) $tx->payment_amount;
                $inBase = $tx->exchange_rate_to_base === null
                    ? $money
                    : bcmul($money, (string) $tx->exchange_rate_to_base, 8);

                return bcadd($carry, $inBase, 8);
            }, '0');

        $cashCollected = bcadd($cashCollected, '0', 2);

        $expectedBalance = bcadd((string) $session->opening_balance, $cashCollected, 2);
        $difference = bcsub($closingBalance, $expectedBalance, 2);

        $session->update([
            'closed_at' => $now,
            'closing_balance' => $closingBalance,
            'expected_balance' => $expectedBalance,
            'difference' => $difference,
            'status' => CashRegisterSessionStatus::Closed,
            'notes' => $notes ?? $session->notes,
        ]);

        return $session->fresh();
    }

    /**
     * Get currently active open session for the user.
     */
    public function currentSession(User $user): ?CashRegisterSession
    {
        return CashRegisterSession::where('user_id', $user->id)
            ->where('status', CashRegisterSessionStatus::Open)
            ->latest('id')
            ->first();
    }
}
