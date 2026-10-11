<?php

namespace App\Domain\Finance\Payments\Contracts;

use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\PaymentStart;

/**
 * One way of paying: a card gateway, the member's wallet, cash at the desk.
 *
 * A driver is registered in config/payments.php and looked up through
 * PaymentMethodRegistry by the value stored in `payments.method`. It knows
 * how to open a payment; it does not decide what the payment is for (that
 * is `PaymentPurpose`) or what to do once it succeeds (the listeners).
 */
interface PaymentMethodDriver
{
    /**
     * The value stored in `payments.method` — also its key in
     * config/payments.php.
     */
    public function key(): string;

    /**
     * Human-readable name, translated through `api.payment.methods.<key>`.
     */
    public function label(): string;

    /**
     * Whether this method can pay for the purpose. What a client is offered
     * is `PaymentMethodRegistry::availableFor()`, built from this.
     */
    public function supports(PaymentPurpose $purpose): bool;

    /**
     * Opens the payment, which already exists as a row.
     *
     * The returned PaymentStart says what the caller does next: nothing (it
     * was settled on the spot), send the member to a hosted page, or tell
     * them to pay at reception.
     *
     * @param  array<string, mixed>  $context  per-call options a method may need
     */
    public function start(Payment $payment, array $context = []): PaymentStart;
}
