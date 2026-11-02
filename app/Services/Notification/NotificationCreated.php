<?php

namespace App\Services\Notification;

use App\Domain\Identity\Enums\NotificationAudience;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A new row in one account's inbox: the open app or dashboard re-fetches its
 * unread count. Only the audience travels — the client reads the inbox it
 * already reads. Member notices go on the member's own channel, staff and
 * provider ones on `private-dashboard.{id}` (routes/channels.php). Sent
 * through RealtimeBroadcaster, never event().
 */
class NotificationCreated implements ShouldBroadcastNow
{
    public function __construct(
        public readonly int $userId,
        public readonly NotificationAudience $audience,
    ) {}

    /** The event name clients bind to — stable, part of the contract. */
    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    /** @return array<string, string> */
    public function broadcastWith(): array
    {
        return ['audience' => $this->audience->value];
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(
            $this->audience === NotificationAudience::Member ? 'member.'.$this->userId : 'dashboard.'.$this->userId,
        )];
    }
}
