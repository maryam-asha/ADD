<?php

namespace App\Domain\Finance\Payments\Contracts;

use App\Domain\Finance\DataTransferObjects\CreatedPayment;
use App\Domain\Finance\DataTransferObjects\PaymentStatusResult;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Models\Payment;
use App\Domain\Identity\Models\User;

/**
 * A method whose outcome lives at a third party, so somebody has to go and
 * ask: a card gateway. Anything that is not one of these settles on its own
 * (wallet points) or is settled by a person (cash), and has no status to
 * fetch and nothing to reverse at a bank.
 *
 * The lifecycle code — SyncPaymentStatus, ReversePayment, the reconcile
 * command — talks only to this interface, through
 * PaymentMethodRegistry::gatewayFor().
 */
interface PaymentGateway extends PaymentMethodDriver
{
    /**
     * Creates the payment at the gateway. Takes the payer separately from
     * the payment so a gateway can pass per-payer options (Paymera's saved
     * cards are filed under the payer's identity).
     *
     * Never retried by the caller: a create that timed out may already have
     * happened at the gateway, and retrying it is how a member is charged
     * twice.
     */
    public function createPayment(Payment $payment, User $payer): CreatedPayment;

    /**
     * What the gateway says happened. The only trustworthy statement about
     * whether the payment succeeded; safe to call repeatedly.
     */
    public function fetchStatus(Payment $payment): PaymentStatusResult;

    /**
     * Reverses an accepted payment at the gateway. Throws if the gateway
     * refuses; the caller only moves the local record once this returns.
     */
    public function cancel(Payment $payment): void;

    /**
     * Name of the (public, token-keyed) route the gateway sends the member
     * back to when the hosted page finishes.
     */
    public function callbackRouteName(): string;

    /**
     * How money that arrived through this gateway is recorded on the
     * ledger: the `payment_method` of the credit an accepted payment writes
     * (PaymentService::fulfilAcceptedGatewayPayment), beside the columns
     * the reception till fills in for cash.
     */
    public function ledgerMethod(): PaymentMethod;
}
