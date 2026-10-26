<?php

namespace App\Domain\Booking\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Something the reception desk's open screens should re-fetch for, on
 * `private-reception` (routes/channels.php). Only ids and a status travel:
 * the dashboard reloads the list it already shows (the arrival requests,
 * the active sessions), so nothing a desk screen does not already read
 * leaves the API this way. Sent through RealtimeBroadcaster, never event().
 */
abstract class ReceptionUpdate implements ShouldBroadcastNow
{
    /** The event name clients bind to — stable, part of the contract. */
    abstract public function broadcastAs(): string;

    /** @return array<string, int|string|null> */
    abstract public function broadcastWith(): array;

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('reception')];
    }
}
