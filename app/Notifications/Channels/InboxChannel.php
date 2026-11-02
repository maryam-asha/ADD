<?php

namespace App\Notifications\Channels;

use App\Notifications\GeneralNotification;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * The database channel, plus the inbox the row belongs to.
 */
class InboxChannel extends DatabaseChannel
{
    /**
     * @return array<string, mixed>
     */
    protected function buildPayload($notifiable, Notification $notification)
    {
        $payload = parent::buildPayload($notifiable, $notification);

        if ($notification instanceof GeneralNotification) {
            $payload['audience'] = $notification->audience->value;
        }

        return $payload;
    }
}
