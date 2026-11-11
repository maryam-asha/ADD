<?php

namespace App\Domain\Kitchen\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Something the kitchen board's open screens should re-fetch for, on
 * `private-kitchen` (routes/channels.php). Only ids and a status travel:
 * the dashboard reloads the order list it already shows. Sent through
 * RealtimeBroadcaster, never event().
 */
abstract class KitchenUpdate implements ShouldBroadcastNow
{
    /** The event name clients bind to — stable, part of the contract. */
    abstract public function broadcastAs(): string;

    /** @return array<string, int|string|null> */
    abstract public function broadcastWith(): array;

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('kitchen')];
    }
}
