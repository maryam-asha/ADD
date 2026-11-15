<?php

namespace App\Domain\Kitchen\Services;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Enums\OrderPaymentChoice;
use App\Domain\Kitchen\Exceptions\KitchenOrderException;
use App\Domain\Kitchen\Models\KitchenOrder;
use Illuminate\Support\Facades\DB;

/**
 * Reception's decision on an order a member sent without a bill (2026-11-14
 * spec, decision 5): the person is in the building and the kitchen sees it,
 * or it is turned down and what was paid goes back. Either way the desk's
 * queue is told, so the row drops. Lock order: the order, then its payment
 * (an order with no bill has no bill to lock first).
 */
class ReceptionOrderReview
{
    use LogsSensitiveActions;

    public function __construct(
        private readonly PaymentService $payments,
        private readonly KitchenOrderRefunder $refunder,
        private readonly KitchenOrderNotifier $notifier,
    ) {}

    /**
     * Lets the order through to the kitchen board. A cash order that is not
     * yet paid has its cash taken in the same transaction: exactly what is
     * due, in `$currency` (default: the base currency). Never more — the
     * desk gives change, and a surplus taken here would stay in the wallet
     * as points, then go back again as cash if the order is not made. One
     * that is already paid (a card payment landed after the member switched
     * to cash) collects nothing and ignores `$currency`.
     *
     * @throws KitchenOrderException not awaiting reception (409)
     * @throws \App\Domain\Finance\Exceptions\PaymentPricingException `$currency` has no rate; nothing is written
     */
    public function accept(KitchenOrder $order, User $staff, ?string $currency): KitchenOrder
    {
        DB::transaction(function () use ($order, $staff, $currency) {
            $locked = $this->locked($order);

            if ($locked->payment_choice === OrderPaymentChoice::Cash && $locked->paid_at === null) {
                $settled = $this->payments->settleAtReception(PaymentPurpose::KitchenOrder, $locked, PaymentMethod::Cash, null, $currency, $staff);
                $locked->refresh();

                // The same entry the desk's cash path writes (ReceptionPaymentController::settle).
                $this->logSensitiveAction('payment_settled_at_reception', $settled->payment, [
                    'purpose' => PaymentPurpose::KitchenOrder->value,
                    'collected_via' => PaymentMethod::Cash->value,
                    'received_amount' => $settled->payment->received_amount,
                    'received_currency' => $settled->payment->received_currency,
                    'points_credited' => $settled->pointsCredited,
                    'points_charged' => $settled->pointsCharged,
                ]);
            }

            $locked->forceFill([
                'status' => KitchenOrderStatus::Pending,
                'reached_kitchen_at' => now(),
                'reception_decided_by' => $staff->id,
                'reception_decided_at' => now(),
            ])->save();

            $this->notifier->placed($locked);
            $this->notifier->acceptedByReception($locked);
        });

        return $order->refresh();
    }

    /**
     * Turns the order down with a reason the member sees; what was paid goes
     * back the way it came (KitchenOrderRefunder).
     *
     * @throws KitchenOrderException not awaiting reception (409)
     */
    public function reject(KitchenOrder $order, User $staff, string $reason): KitchenOrder
    {
        DB::transaction(function () use ($order, $staff, $reason) {
            $locked = $this->locked($order);

            $locked->forceFill([
                'status' => KitchenOrderStatus::Rejected,
                'rejection_reason' => trim($reason),
                'rejected_by' => $staff->id,
                'rejected_at' => now(),
                'reception_decided_by' => $staff->id,
                'reception_decided_at' => now(),
            ])->save();

            $this->refunder->refund($locked, __('api.kitchen.refund_description', ['order' => '#'.$locked->id]));
            $this->notifier->rejectedByReception($locked);
        });

        return $order->refresh();
    }

    /** The order's row, locked and read afresh, refused (409) unless it still awaits reception. */
    private function locked(KitchenOrder $order): KitchenOrder
    {
        $locked = KitchenOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->status !== KitchenOrderStatus::AwaitingReception) {
            throw new KitchenOrderException('api.kitchen.order_not_awaiting_reception', 409);
        }

        return $locked;
    }
}
