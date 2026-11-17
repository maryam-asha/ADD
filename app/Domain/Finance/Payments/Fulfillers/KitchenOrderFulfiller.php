<?php

namespace App\Domain\Finance\Payments\Fulfillers;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Events\BookingPaid;
use App\Domain\Booking\Events\CoworkingSessionPaid;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Contracts\PaymentFulfiller;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Enums\OrderPaymentChoice;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Kitchen\Services\KitchenOrderNotifier;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Services\Realtime\RealtimeBroadcaster;

/**
 * Paying for a kitchen order: one the kitchen accepted, owed beside the
 * session or booking it was sent from, or one paid when it is sent
 * (2026-11-14 spec, decision 3). Either way it is paid on its own (kitchen
 * orders spec, decision 11): from general points only — points granted for
 * a space never pay for the kitchen — and recorded as this purpose's sale,
 * never as the bill's revenue. Its price was fixed when it was sent.
 *
 * Unlike a reception sale, delivering it issues nothing: accepting the
 * order draws its stock (KitchenOrderService::accept), so paying it only
 * marks it paid — and, for an order held for its card, lets it go on to
 * its route (KitchenOrder::releaseFromHold()), announced to whoever now
 * sees it; for a cash order already at the desk that an old card paid
 * after all, the desk's row is told to re-fetch.
 *
 * Once its session or booking has closed, the bill's home card stays
 * awaiting_payment while an order on it is unpaid (CurrentBookingLocator,
 * OutstandingItems::closedFor), so paying one tells the member's app as
 * paying the bill does (SessionPaymentFulfiller): BookingPaid, with the
 * card's state as it is now, for a room booking; CoworkingSessionPaid for
 * a coworking session. On a bill still open, or one still owing itself,
 * no card changes: the bill's own payment tells the app (settled after its
 * orders, so it finds nothing left owing).
 */
class KitchenOrderFulfiller implements PaymentFulfiller
{
    /** Not yet accepted, and still payable: paying at send. */
    private const PAYABLE_BEFORE_ACCEPTED = [
        KitchenOrderStatus::AwaitingPayment,
        KitchenOrderStatus::AwaitingReception,
        KitchenOrderStatus::Pending,
    ];

    public function __construct(
        private readonly RealtimeBroadcaster $realtime,
        private readonly KitchenOrderNotifier $notifier,
    ) {}

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

        // Accepted and owed on its bill; or paid when it is sent, before the
        // kitchen has it — held for its card, waiting for reception (cash
        // taken there), or pending (2026-11-14 spec, decision 3). Rejected
        // or cancelled, it is never made.
        if (! $order->status->isOwed() && ! in_array($order->status, self::PAYABLE_BEFORE_ACCEPTED, true)) {
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
        $order = $this->order($payment);
        $order->forceFill(['paid_at' => now()])->save();

        // Its card went through: out of the hold, on to its route.
        if ($order->status === KitchenOrderStatus::AwaitingPayment) {
            $order->releaseFromHold();
            $this->notifier->released($order);
        } elseif ($order->status === KitchenOrderStatus::AwaitingReception
            && $order->payment_choice === OrderPaymentChoice::Cash
            && $payment->collected_via !== PaymentMethod::Cash) {
            // A late card on a cash order at the desk: no longer cash to
            // collect. The desk's own cash (ReceptionOrderReview::accept)
            // and a payment taken at send are announced by their own paths.
            $this->notifier->paidWhileAtReception($order);
        }

        $bill = $order->billable;

        if (($bill instanceof Booking || $bill instanceof WalkinSession)
            && $bill->checked_out_at !== null
            && $bill->payment_state === PaymentState::Paid) {
            $this->realtime->send($bill instanceof Booking && ! $bill->isCoworking()
                ? new BookingPaid($bill)
                : new CoworkingSessionPaid($bill));
        }
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
