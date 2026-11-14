<?php

namespace App\Domain\Kitchen\Services;

use App\Domain\Kitchen\Enums\DeliveryType;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Events\KitchenOrderAwaitingReception;
use App\Domain\Kitchen\Events\KitchenOrderChanged;
use App\Domain\Kitchen\Events\MemberKitchenOrderUpdated;
use App\Domain\Kitchen\Models\KitchenOrder;
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

    /** An order reached the kitchen: the board only — the member just sent it. */
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

    /** The member took it back: the board only. */
    public function cancelledByMember(KitchenOrder $order): void
    {
        $this->realtime->send(new KitchenOrderChanged($order));
    }

    public function accepted(KitchenOrder $order): void
    {
        $this->changed($order, 'kitchen_order.accepted');
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
