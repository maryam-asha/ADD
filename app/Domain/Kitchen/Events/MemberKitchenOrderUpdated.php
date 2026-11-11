<?php

namespace App\Domain\Kitchen\Events;

use App\Domain\Booking\Events\MemberUpdate;
use App\Domain\Kitchen\Models\KitchenOrder;

/** The member's order changed status: their app re-fetches it. */
class MemberKitchenOrderUpdated extends MemberUpdate
{
    public readonly int $orderId;

    public readonly string $status;

    public function __construct(KitchenOrder $order)
    {
        parent::__construct((int) $order->user_id);

        $this->orderId = (int) $order->id;
        $this->status = $order->status->value;
    }

    public function broadcastAs(): string
    {
        return 'kitchen_order.updated';
    }

    public function broadcastWith(): array
    {
        return ['order_id' => $this->orderId, 'status' => $this->status];
    }
}
