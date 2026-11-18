<?php

namespace App\Domain\Booking\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Something one member's app should re-fetch for, on
 * `private-member.{id}` (routes/channels.php): GET
 * member/coworking-sessions/current and GET member/home hold the state, the
 * event only says it changed. The FCM push sent for the same change stays
 * — it is what reaches an app that is closed. Sent through
 * RealtimeBroadcaster, never event().
 */
abstract class MemberUpdate implements ShouldBroadcastNow
{
    public function __construct(public readonly int $memberId) {}

    /** The event name clients bind to — stable, part of the contract. */
    abstract public function broadcastAs(): string;

    /** @return array<string, int|string|null> */
    abstract public function broadcastWith(): array;

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('member.'.$this->memberId)];
    }
}
