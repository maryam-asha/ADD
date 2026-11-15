<?php

namespace App\Domain\Kitchen\Services;

use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Kitchen\Enums\CashReturn;
use App\Domain\Kitchen\Models\KitchenOrder;

/**
 * Gives back what an order that was not made took (2026-11-14 spec,
 * decision 4). Every settled payment on the order is returned the way it
 * came: points as points, to the wallet that paid — a card too, since a card
 * payment became points when it was accepted, so no money goes back through
 * the gateway — and cash is not turned into points: the order is marked
 * `owed` and the member is told to collect it at reception.
 *
 * Runs inside the caller's transaction, on the order it has locked. Lock
 * order here: bill, then order, then payment (the payment rows are locked
 * by this class and by PaymentService::refund). The one path that takes the
 * opposite order is the bank-callback sync, which locks the payment first,
 * then credits the card's points to the wallet — touching the wallet row —
 * and only then, through KitchenOrderFulfiller, locks the order. A callback
 * landing on the same order as a cancel can therefore deadlock; so can one
 * landing beside reception's accept (ReceptionOrderReview::accept: the
 * order, then the wallet, as it takes the cash) or a bill closing (the
 * bill, the order, then the wallet its refunds credit). Every victim is safe
 * with money: a sync that loses rolls back and the reconciler retries it,
 * and a cancel, an accept or a closing that loses fails before anything
 * committed, so it can be sent again. Idempotent: a refunded payment is skipped and
 * `cash_return` is written only while empty.
 */
class KitchenOrderRefunder
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly KitchenOrderNotifier $notifier,
    ) {}

    public function refund(KitchenOrder $order, string $description): void
    {
        $rows = Payment::query()
            ->forPayable($order)
            ->where('status', PaymentStatus::Accepted)
            ->whereNull('refunded_at')
            ->lockForUpdate()
            ->get();

        foreach ($rows as $payment) {
            if ($payment->collected_via === PaymentMethod::Cash) {
                if ($order->cash_return === null) {
                    $order->forceFill(['cash_return' => CashReturn::Owed])->save();
                    $this->notifier->cashToCollect($order);
                }

                continue;
            }

            $this->payments->refund($payment, $description);
            $this->notifier->refunded($order, $payment->refresh());
        }
    }
}
