<?php

namespace App\Domain\Kitchen\Services;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Enums\CashReturn;
use App\Domain\Kitchen\Exceptions\KitchenOrderException;
use App\Domain\Kitchen\Models\KitchenOrder;
use Illuminate\Support\Facades\DB;

/**
 * Settles the cash owed to a member for an order that was not made (2026-11-14
 * spec, decision 4): reception hands it back across the desk, or credits it
 * as points when the member asks. Lock order: the order, then its payment.
 */
class CashReturns
{
    use LogsSensitiveActions;

    public function __construct(private readonly PaymentService $payments) {}

    /**
     * The cash was handed back: no wallet moves, the payment is marked
     * refunded and the order records who did it.
     *
     * @throws KitchenOrderException 409 api.kitchen.no_cash_owed
     */
    public function returnedInCash(KitchenOrder $order, User $operator): KitchenOrder
    {
        return $this->resolve($order, $operator, CashReturn::ReturnedInCash, 'kitchen_cash_returned', function (Payment $payment) use ($operator) {
            $this->payments->markReturnedInCash($payment, $operator);
        });
    }

    /**
     * The member asked for points instead: the payment's points go to their
     * wallet, as any refund does.
     *
     * @throws KitchenOrderException 409 api.kitchen.no_cash_owed
     */
    public function creditAsPoints(KitchenOrder $order, User $operator): KitchenOrder
    {
        return $this->resolve($order, $operator, CashReturn::CreditedAsPoints, 'kitchen_cash_credited_as_points', function (Payment $payment) use ($order) {
            $this->payments->refund($payment, __('api.kitchen.cash_returned_description', ['order' => '#'.$order->id]));
        });
    }

    /** @param callable(Payment): void $act moves the payment */
    private function resolve(KitchenOrder $order, User $operator, CashReturn $outcome, string $action, callable $act): KitchenOrder
    {
        DB::transaction(function () use ($order, $operator, $outcome, $action, $act) {
            $locked = KitchenOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->cash_return !== CashReturn::Owed) {
                throw new KitchenOrderException('api.kitchen.no_cash_owed', 409);
            }

            $payment = Payment::query()
                ->forPayable($locked)
                ->where('status', PaymentStatus::Accepted)
                ->where('collected_via', PaymentMethod::Cash)
                ->whereNull('refunded_at')
                ->latest('id')
                ->first();

            if ($payment === null) {
                throw new KitchenOrderException('api.kitchen.no_cash_owed', 409);
            }

            $act($payment);

            $locked->forceFill([
                'cash_return' => $outcome,
                'cash_return_resolved_by' => $operator->id,
                'cash_return_resolved_at' => now(),
            ])->save();

            $this->logSensitiveAction($action, $locked, [
                'order_id' => $locked->id,
                'payment_id' => $payment->id,
                'received_amount' => $payment->received_amount,
                'received_currency' => $payment->received_currency,
                'points' => $payment->amount_points,
                'operator_id' => $operator->id,
            ]);
        });

        return $order->refresh();
    }
}
