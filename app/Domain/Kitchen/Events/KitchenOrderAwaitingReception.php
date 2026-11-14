<?php

namespace App\Domain\Kitchen\Events;

use App\Domain\Booking\Events\ReceptionUpdate;
use App\Domain\Kitchen\Models\KitchenOrder;

/**
 * The desk's queue of kitchen orders to confirm changed
 * (`kitchen_order.awaiting_reception`, on `private-reception`): an order
 * arrived in it — sent in cash, from a wallet, or its card just went
 * through — or left it (`status` says which: still `awaiting_reception`,
 * or what reception or the expiry made of it). The queue re-fetches.
 */
class KitchenOrderAwaitingReception extends ReceptionUpdate
{
    public readonly int $orderId;

    public readonly int $branchId;

    public readonly string $status;

    public function __construct(KitchenOrder $order)
    {
        $this->orderId = (int) $order->id;
        $this->branchId = (int) $order->branch_id;
        $this->status = $order->status->value;
    }

    public function broadcastAs(): string
    {
        return 'kitchen_order.awaiting_reception';
    }

    public function broadcastWith(): array
    {
        return ['order_id' => $this->orderId, 'branch_id' => $this->branchId, 'status' => $this->status];
    }
}
