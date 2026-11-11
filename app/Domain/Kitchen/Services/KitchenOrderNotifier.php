<?php

namespace App\Domain\Kitchen\Services;

use App\Domain\Kitchen\Enums\DeliveryType;
use App\Domain\Kitchen\Events\KitchenOrderChanged;
use App\Domain\Kitchen\Events\MemberKitchenOrderUpdated;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Services\Notification\NotificationService;
use App\Services\Realtime\RealtimeBroadcaster;

/**
 * What the kitchen board and the member hear about an order. Both ways run
 * after the surrounding transaction commits (RealtimeBroadcaster and
 * NotificationService defer to it), so a change that rolls back is never
 * announced. The board is live, so staff get no inbox notice per order.
 */
class KitchenOrderNotifier
{
    public function __construct(
        private readonly RealtimeBroadcaster $realtime,
        private readonly NotificationService $notifications,
    ) {}

    /** A new order: the kitchen board only — the member just sent it. */
    public function placed(KitchenOrder $order): void
    {
        $this->realtime->send(new KitchenOrderChanged($order, created: true));
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
