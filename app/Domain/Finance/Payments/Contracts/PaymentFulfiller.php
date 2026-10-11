<?php

namespace App\Domain\Finance\Payments\Contracts;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Domain\Membership\Enums\WalletTransactionCategory;

/**
 * What one payment purpose buys: what is due for it, how the ledger
 * describes the spend, and what happens once it is paid.
 *
 * The counterpart of PaymentMethodDriver. A method knows how money arrives
 * and nothing about bookings or plans; a fulfiller knows what was bought and
 * nothing about how it was paid. PaymentService joins the two, and
 * FulfillerRegistry (wired in PaymentServiceProvider) maps each purpose to
 * its fulfiller.
 *
 * Every method runs inside PaymentService's transaction, with the payment
 * row locked. None of them credits or debits the payment's money — the
 * service does that — so a fulfiller only delivers the thing bought (which
 * may grant balances of its own, as a plan does).
 */
interface PaymentFulfiller
{
    public function purpose(): PaymentPurpose;

    /**
     * The points to debit for this payment, read at settle time.
     *
     * The contract later fulfillers rely on:
     *
     *  - a purpose whose price is fixed when the payment is opened (a
     *    booking, a session, an event registration) returns
     *    `$payment->amount_points` when it is set, so what the member was
     *    quoted is what they pay;
     *  - a plan purchase always re-reads `plans.price_points`, so a plan
     *    repriced between opening and settling is caught, never granted at
     *    the old price;
     *  - a top-up returns 0 — it spends nothing.
     */
    public function pointsDue(Payment $payment): int;

    /**
     * The wallet category the points are spent from. WalletService::debit()
     * falls back to the general balance when the category cannot cover it.
     */
    public function walletCategory(): WalletTransactionCategory;

    /**
     * The description of the debit, as it appears in the wallet history.
     */
    public function describe(Payment $payment): string;

    /**
     * Refuses a payment for something that can no longer be bought — a
     * withdrawn plan, a cancelled booking. Called before anything is spent.
     *
     * @throws ReceptionActionException carrying the translation key
     */
    public function assertPayable(Payment $payment): void;

    /**
     * Delivers what was paid for. Runs after the points are spent; throwing
     * here undoes the spend with the rest of the transaction.
     */
    public function fulfil(Payment $payment): void;
}
