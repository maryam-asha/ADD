<?php

namespace App\Domain\Kitchen\Events;

use App\Domain\Booking\Events\MemberUpdate;
use App\Domain\Kitchen\Models\KitchenOrder;

/**
 * The member's order changed status: their app re-fetches it. It names the
 * coworking session or booking the order is billed to — `session_type`
 * ('booking' | 'walkin', as `coworking-sessions/{type}/{id}` takes them) and
 * `session_id`, both null for an order with no bill — so the session screen
 * (coworking-sessions/current, its checkout, a booking's detail) can follow
 * the status of the orders on it from this one event.
 */
class MemberKitchenOrderUpdated extends MemberUpdate
{
    public readonly int $orderId;

    public readonly string $status;

    public readonly ?string $sessionType;

    public readonly ?int $sessionId;

    public function __construct(KitchenOrder $order)
    {
        parent::__construct((int) $order->user_id);

        $this->orderId = (int) $order->id;
        $this->status = $order->status->value;
        $this->sessionType = $order->billType();
        $this->sessionId = $order->hasBill() ? (int) $order->billable_id : null;
    }

    public function broadcastAs(): string
    {
        return 'kitchen_order.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->orderId,
            'status' => $this->status,
            'session_type' => $this->sessionType,
            'session_id' => $this->sessionId,
        ];
    }
}
