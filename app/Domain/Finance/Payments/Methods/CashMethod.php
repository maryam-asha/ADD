<?php

namespace App\Domain\Finance\Payments\Methods;

use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Contracts\PaymentMethodDriver;
use App\Domain\Finance\Payments\PaymentStart;

/**
 * Paying at the reception desk. The member only says they will; the money
 * is counted, and the payment settled, by reception.
 *
 * A booking, a session or an event registration simply stays unpaid until
 * then — its own unpaid state is the record, and reception writes the
 * payment row when it takes the money. A plan cannot wait like that (there
 * is no membership yet to be unpaid), so a plan bought with cash is held as
 * a pending payment that expires: see createsPendingRowFor().
 *
 * A top-up is offered too, but only as directions: choosing it writes
 * nothing and answers `pay_at_reception`. The member hands the cash over at
 * the desk, which credits it through the reception top-up — what they
 * actually hand over is the amount, so a request made in advance would only
 * hold a figure nobody uses, and a row someone has to expire.
 */
class CashMethod implements PaymentMethodDriver
{
    public const KEY = 'cash';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('api.payment.methods.'.self::KEY);
    }

    /**
     * Not an extension: it is settled from the wallet as it happens, or the
     * booking is left owing it.
     */
    public function supports(PaymentPurpose $purpose): bool
    {
        return $purpose !== PaymentPurpose::BookingExtension;
    }

    /**
     * Whether choosing cash for `$purpose` writes a pending payment row now.
     * Only a plan does; everything else is recorded by reception when paid.
     */
    public function createsPendingRowFor(PaymentPurpose $purpose): bool
    {
        return $purpose === PaymentPurpose::PlanPurchase;
    }

    /**
     * The row stays Pending, held for `payments.cash_plan_hold_hours`, after
     * which payments:expire-pending lets it go.
     */
    public function start(Payment $payment, array $context = []): PaymentStart
    {
        if ($this->createsPendingRowFor($payment->purpose)) {
            $payment->forceFill([
                'expires_at' => now()->addHours((int) config('payments.cash_plan_hold_hours', 48)),
            ])->save();
        }

        return PaymentStart::payAtReception();
    }
}
