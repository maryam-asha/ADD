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

        // Sum cash transactions handled by this operator during the session interval
        $cashCollected = WalletTransaction::query()
            ->where('performed_by_user_id', $session->user_id)
            ->where('payment_method', PaymentMethod::Cash->value)
            ->whereBetween('created_at', [$session->opened_at, $now])
            ->get()
            ->reduce(function ($carry, WalletTransaction $tx) {
                return bcadd($carry, (string) $tx->amount, 2);
            }, '0.00');

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
