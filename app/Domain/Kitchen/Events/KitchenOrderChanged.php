<?php

namespace App\Domain\Kitchen\Events;

use App\Domain\Kitchen\Models\KitchenOrder;

/** An order reached the kitchen (`kitchen_order.created`) or changed status (`kitchen_order.updated`). */
class KitchenOrderChanged extends KitchenUpdate
{
    public readonly int $orderId;

    public readonly int $branchId;

    public readonly string $status;

    public function __construct(KitchenOrder $order, public readonly bool $created = false)
    {
        $this->orderId = (int) $order->id;
        $this->branchId = (int) $order->branch_id;
        $this->status = $order->status->value;
    }

    public function broadcastAs(): string
    {
        return $this->created ? 'kitchen_order.created' : 'kitchen_order.updated';
    }

    public function broadcastWith(): array
    {
        return ['order_id' => $this->orderId, 'branch_id' => $this->branchId, 'status' => $this->status];
    }
}
