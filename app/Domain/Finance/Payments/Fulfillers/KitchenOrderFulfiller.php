<?php

namespace App\Domain\Finance\Payments\Fulfillers;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Contracts\PaymentFulfiller;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Membership\Enums\WalletTransactionCategory;

/**
 * Paying for an order the kitchen accepted. It is owed beside the session
 * or booking it was sent from, but paid on its own (kitchen orders spec,
 * decision 11): from general points only — points granted for a space never
 * pay for the kitchen — and recorded as this purpose's sale, never as the
 * bill's revenue. Its price was fixed when it was sent.
 *
 * Unlike a reception sale, delivering it issues nothing: accepting the
 * order already drew its stock (KitchenOrderService::accept), so paying it
 * only marks it paid.
 */
class KitchenOrderFulfiller implements PaymentFulfiller
{
    public function purpose(): PaymentPurpose
    {
        return PaymentPurpose::KitchenOrder;
    }

    public function pointsDue(Payment $payment): int
    {
        return $payment->amount_points ?? $this->order($payment)->total_points;
    }

    /**
     * General, never the categorised pools — and so never space-specific,
     * which debit() would otherwise try first. Whether a `cafe` pool should
     * pay for the kitchen is the spec's open question 3.
     */
    public function walletCategory(): WalletTransactionCategory
    {
        return WalletTransactionCategory::General;
    }

    public function describe(Payment $payment): string
    {
        return __('api.kitchen_order.description', ['id' => $payment->payable_id]);
    }

    public function assertPayable(Payment $payment): void
    {
        $order = $this->order($payment);

        // Pending, rejected or cancelled: it never reached the bill.
        if (! $order->status->isOwed()) {
            throw new ReceptionActionException('api.kitchen_order.not_payable');
        }

        if ($order->paid_at !== null) {
            throw new ReceptionActionException('api.payment.already_paid', 409);
        }

        if ($order->total_points <= 0) {
            throw new ReceptionActionException('api.payment.nothing_due');
        }
    }

    public function fulfil(Payment $payment): void
    {
        $this->order($payment)->forceFill(['paid_at' => now()])->save();
    }

    private function order(Payment $payment): KitchenOrder
    {
        $order = $payment->payable_id === null
            ? null
            : KitchenOrder::query()->whereKey($payment->payable_id)->lockForUpdate()->first();

        if ($order === null || $payment->payable_type !== (new KitchenOrder)->getMorphClass()) {
            throw new ReceptionActionException('api.payment.payable_not_found', 404);
        }

        return $order;
    }
}
