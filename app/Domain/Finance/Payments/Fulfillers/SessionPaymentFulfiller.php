<?php

namespace App\Domain\Finance\Payments\Fulfillers;

use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Contracts\PaymentFulfiller;
use App\Domain\Finance\Payments\PaymentMethodRegistry;
use App\Domain\Membership\Enums\WalletTransactionCategory;

/**
 * What a booking and a walk-in have in common once they are paid for: both
 * are time in a space, spent from the space-specific pool (falling back to
 * general), and both record on themselves how they were paid — the same
 * `payment_state` / `payment_source` / `payment_method` / `paid_at` /
 * `paid_by` columns reception's settle-payment has always written.
 */
abstract class SessionPaymentFulfiller implements PaymentFulfiller
{
    public function __construct(protected readonly PaymentMethodRegistry $methods) {}

    public function walletCategory(): WalletTransactionCategory
    {
        return WalletTransactionCategory::SpaceSpecific;
    }

    public function fulfil(Payment $payment): void
    {
        $this->session($payment)->forceFill([
            'payment_state' => PaymentState::Paid,
            'payment_source' => $this->sourceFor($payment),
            'payment_method' => $this->ledgerMethodFor($payment),
            'paid_at' => now(),
            'paid_by' => $payment->received_by,
        ])->save();
    }

    abstract protected function session(Payment $payment): Booking|WalkinSession;

    protected function assertNotPaid(Booking|WalkinSession $session): void
    {
        if ($session->payment_state === PaymentState::Paid) {
            throw new ReceptionActionException('api.payment.already_paid', 409);
        }
    }

    /**
     * The payable, read fresh and locked: every check and the state change
     * below must see the row as it is now, not as the caller loaded it.
     *
     * @template T of Booking|WalkinSession
     *
     * @param  class-string<T>  $class
     * @return T
     */
    protected function lockedPayable(Payment $payment, string $class): Booking|WalkinSession
    {
        $session = $payment->payable_id === null
            ? null
            : $class::query()->whereKey($payment->payable_id)->lockForUpdate()->first();

        if ($session === null || $payment->payable_type !== (new $class)->getMorphClass()) {
            throw new ReceptionActionException('api.payment.payable_not_found', 404);
        }

        return $session;
    }

    private function sourceFor(Payment $payment): PaymentSource
    {
        return match (true) {
            $payment->method === 'wallet' => PaymentSource::Wallet,
            in_array((string) $payment->method, $this->methods->gatewayKeys(), true) => PaymentSource::Gateway,
            default => PaymentSource::Cash,
        };
    }

    /**
     * How the money reached us, on the session's own column: what reception
     * says it collected, or the gateway's ledger method. Points spent from a
     * wallet are not money, so nothing.
     */
    private function ledgerMethodFor(Payment $payment): ?PaymentMethod
    {
        if ($payment->collected_via !== null) {
            return $payment->collected_via;
        }

        if (in_array((string) $payment->method, $this->methods->gatewayKeys(), true)) {
            return $this->methods->gatewayFor($payment)->ledgerMethod();
        }

        return null;
    }
}
