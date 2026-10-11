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
 * payment row when it takes the money.
 *
 * A plan is not offered: there is no subscription without payment, so a
 * plan is bought with points or a card only (docs/decisions/unified-payments.md).
 * Cash holds opened before that still settle at reception or lapse
 * (payments:expire-pending).
 *
 * A top-up is not offered at all: the member hands the cash over at the
 * desk, which credits it through the reception top-up
 * (admin/reception/wallet-top-ups) — what they actually hand over is the
 * amount, so a request made in advance would only hold a figure nobody
 * uses, and a row someone has to expire.
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
     * booking is left owing it. Not a member's top-up: cash for points is
     * taken by reception (admin/reception/wallet-top-ups), which writes its
     * own row and never asks this. Not a plan: a plan is paid when bought.
     */
    public function supports(PaymentPurpose $purpose): bool
    {
        return ! in_array($purpose, [PaymentPurpose::BookingExtension, PaymentPurpose::WalletTopUp, PaymentPurpose::PlanPurchase], true);
    }

    /** Nothing is written now: reception records the payment when paid. */
    public function start(Payment $payment, array $context = []): PaymentStart
    {
        return PaymentStart::payAtReception();
    }
}
