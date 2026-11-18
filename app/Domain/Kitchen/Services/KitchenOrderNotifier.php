<?php

namespace App\Domain\Kitchen\Services;

use App\Domain\Finance\Models\Payment;
use App\Domain\Identity\Models\Company;
use App\Domain\Kitchen\Enums\DeliveryType;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Events\KitchenOrderAwaitingReception;
use App\Domain\Kitchen\Events\KitchenOrderChanged;
use App\Domain\Kitchen\Events\MemberKitchenOrderUpdated;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Membership\Enums\OwnerType;
use App\Services\Notification\NotificationService;
use App\Services\Realtime\RealtimeBroadcaster;

/**
 * What the kitchen board, the reception desk and the member hear about an
 * order. Every way runs after the surrounding transaction commits
 * (RealtimeBroadcaster and NotificationService defer to it), so a change
 * that rolls back is never announced. The board is live, so kitchen staff
 * get no inbox notice per order; the desk does, for an order to confirm.
 */
class KitchenOrderNotifier
{
    /** Who confirms an order sent without a bill (2026-11-14 spec, "Permission"). */
    public const REVIEW_PERMISSION = 'kitchen-orders.review';

    public function __construct(
        private readonly RealtimeBroadcaster $realtime,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * An order has just arrived where it is first seen — sent, or released
     * from its card hold: the kitchen board when it is pending, the desk
     * when it awaits reception, and nobody while it still waits for its card.
     */
    public function arrived(KitchenOrder $order): void
    {
        match ($order->status) {
            KitchenOrderStatus::Pending => $this->placed($order),
            KitchenOrderStatus::AwaitingReception => $this->awaitingReception($order),
            default => null,
        };
    }

    /**
     * The member has just sent an order, whatever stage it starts on (held
     * for its card, at reception, or at the kitchen): their app hears it
     * (`kitchen_order.created`) so member/home and the session show it at
     * once, on every device, and whoever first sees it is told (arrived()).
     */
    public function sent(KitchenOrder $order): void
    {
        $this->realtime->send(new MemberKitchenOrderUpdated($order, created: true));

        $this->arrived($order);
    }

    /**
     * A held order is out of its card hold — paid, or another method chosen
     * — and on to its route: the member's app hears its new status, and
     * whoever now sees it is told (arrived()).
     */
    public function released(KitchenOrder $order): void
    {
        $this->realtime->send(new MemberKitchenOrderUpdated($order));

        $this->arrived($order);
    }

    /** An order reached the kitchen: the board only — the member is told by sent() or released(). */
    public function placed(KitchenOrder $order): void
    {
        $this->realtime->send(new KitchenOrderChanged($order, created: true));
    }

    /**
     * An order waits for reception to confirm the member is here: the desk's
     * queue re-fetches, and whoever reviews orders is told.
     */
    public function awaitingReception(KitchenOrder $order): void
    {
        $this->realtime->send(new KitchenOrderAwaitingReception($order));

        $this->notifications->sendToHolders(self::REVIEW_PERMISSION, 'kitchen_order.awaiting_reception', [
            'order' => '#'.$order->id,
            'member' => (string) $order->user?->name,
        ], [
            'target_type' => 'kitchen_order',
            'target_id' => (string) $order->id,
        ]);
    }

    /**
     * A card went through on a cash order already in the desk's queue (the
     * member switched to cash, and the old card landed after all): the row
     * re-fetches, so it shows the order paid instead of cash to collect.
     * Nobody is sent a notice; the order was announced when it arrived.
     */
    public function paidWhileAtReception(KitchenOrder $order): void
    {
        $this->realtime->send(new KitchenOrderAwaitingReception($order));
    }

    /**
     * Reception let an order through: the desk drops the row, the kitchen
     * board (placed()) is told by the caller, and the member hears it is
     * being made.
     */
    public function acceptedByReception(KitchenOrder $order): void
    {
        $this->realtime->send(new KitchenOrderAwaitingReception($order), new MemberKitchenOrderUpdated($order));

        $this->tellMember($order, 'kitchen_order.accepted_by_reception');
    }

    /** Reception turned an order down: the desk drops the row, the member hears why. */
    public function rejectedByReception(KitchenOrder $order): void
    {
        $this->realtime->send(new KitchenOrderAwaitingReception($order), new MemberKitchenOrderUpdated($order));

        $this->tellMember($order, 'kitchen_order.rejected_by_reception', ['reason' => (string) $order->rejection_reason]);
    }

    /**
     * The member took it back: the board, and the desk's queue when it was
     * waiting there (`$wasAwaitingReception`) so the row drops.
     */
    public function cancelledByMember(KitchenOrder $order, bool $wasAwaitingReception = false): void
    {
        $this->realtime->send(new KitchenOrderChanged($order), ...($wasAwaitingReception ? [new KitchenOrderAwaitingReception($order)] : []));
    }

    public function accepted(KitchenOrder $order): void
    {
        $this->changed($order, 'kitchen_order.accepted');
    }

    /**
     * Accepted with fewer items than were ordered: the board and the
     * member's app hear it as any acceptance, and the member is told it
     * changed — with the points given back (`$refundedPoints`), or, for an
     * order on a bill not paid yet, that only what is made is charged.
     */
    public function acceptedWithChanges(KitchenOrder $order, int $refundedPoints): void
    {
        $refundedPoints > 0
            ? $this->changed($order, 'kitchen_order.accepted_with_changes_refunded', ['points' => $refundedPoints])
            : $this->changed($order, 'kitchen_order.accepted_with_changes');
    }

    public function rejected(KitchenOrder $order): void
    {
        $this->changed($order, 'kitchen_order.rejected', ['reason' => (string) $order->rejection_reason]);
    }

    /** The member is pushed "ready" only when they collect it themselves. */
    public function ready(KitchenOrder $order): void
    {
        $this->changed($order, $order->delivery_type === DeliveryType::Pickup ? 'kitchen_order.ready' : null);
    }

    public function delivered(KitchenOrder $order): void
    {
        $this->changed($order, 'kitchen_order.delivered');
    }

    public function cancelledBySystem(KitchenOrder $order): void
    {
        $this->changed($order, 'kitchen_order.cancelled_by_system');
    }

    /**
     * Nobody acted on the order in time and the system cancelled it
     * (`$why`: `payment` - its card never went through; `reception` - the
     * desk never answered; `kitchen` - the kitchen never accepted). The board
     * and the member's app are told, the desk's queue drops the row when it
     * was waiting there, and the member is told why; what they get back is
     * announced by refunded() / cashToCollect() as for any refund.
     */
    public function expired(KitchenOrder $order, string $why): void
    {
        $this->realtime->send(
            new KitchenOrderChanged($order),
            new MemberKitchenOrderUpdated($order),
            ...($why === 'reception' ? [new KitchenOrderAwaitingReception($order)] : []),
        );

        $this->tellMember($order, 'kitchen_order.expired_'.$why);
    }

    /**
     * An order that was not made gave its points back: the member is told how
     * many, and whether to their own wallet or to the company's that paid.
     */
    public function refunded(KitchenOrder $order, Payment $payment): void
    {
        $member = $order->user;

        if ($member === null) {
            return;
        }

        $wallet = $payment->wallet;
        $company = $wallet?->owner_type === OwnerType::Company
            ? (Company::query()->whereKey($wallet->owner_id)->value('legal_name') ?: '#'.$wallet->owner_id)
            : null;

        $this->notifications->sendToUser(
            $member,
            $company === null ? 'kitchen_order.refunded' : 'kitchen_order.refunded_company',
            ['order' => '#'.$order->id, 'points' => (int) $payment->amount_points] + ($company === null ? [] : ['company' => $company]),
            [
                'target_type' => 'kitchen_order',
                'target_id' => (string) $order->id,
            ],
        );
    }

    /** Cash paid for an order that was not made waits at reception. */
    public function cashToCollect(KitchenOrder $order): void
    {
        $member = $order->user;

        if ($member === null) {
            return;
        }

        $this->notifications->sendToUser($member, 'kitchen_order.cash_to_collect', ['order' => '#'.$order->id], [
            'target_type' => 'kitchen_order',
            'target_id' => (string) $order->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $placeholders
     */
    private function tellMember(KitchenOrder $order, string $templateKey, array $placeholders = []): void
    {
        $member = $order->user;

        if ($member === null) {
            return;
        }

        $this->notifications->sendToUser($member, $templateKey, ['order' => '#'.$order->id] + $placeholders, [
            'target_type' => 'kitchen_order',
            'target_id' => (string) $order->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $placeholders
     */
    private function changed(KitchenOrder $order, ?string $templateKey, array $placeholders = []): void
    {
        $this->realtime->send(new KitchenOrderChanged($order), new MemberKitchenOrderUpdated($order));

        $member = $templateKey === null ? null : $order->user;

        if ($member === null) {
            return;
        }

        $this->notifications->sendToUser($member, $templateKey, ['order' => '#'.$order->id] + $placeholders, [
            'target_type' => 'kitchen_order',
            'target_id' => (string) $order->id,
        ]);
    }
}
