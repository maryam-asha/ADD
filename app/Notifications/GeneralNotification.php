<?php

namespace App\Notifications;

use App\Domain\Identity\Enums\NotificationAudience;
use App\Notifications\Channels\InboxChannel;
use App\Services\Notification\NotificationText;
use Illuminate\Notifications\Notification;

/**
 * The one in-app notification, written to the inbox of its audience. Not
 * queued: the host runs no worker, so a queued row would never be written.
 *
 * The row keeps the template key and its params, rendered in the reader's
 * language when the inbox is read (NotificationResource). `title`/`body`
 * are a snapshot in the account's language, shown only if the template is
 * gone. A custom (broadcast) notice keeps its title and body per locale.
 */
class GeneralNotification extends Notification
{
    /**
     * @param  string|array<string, string>|null  $customTitle  per locale for a broadcast
     * @param  string|array<string, string>|null  $customBody  per locale for a broadcast
     */
    public function __construct(
        private readonly string $templateKey,
        private readonly array $placeholders = [],
        private readonly array $extraData = [],
        private readonly string|array|null $customTitle = null,
        private readonly string|array|null $customBody = null,
        public readonly NotificationAudience $audience = NotificationAudience::Member,
    ) {}

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return [InboxChannel::class];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        $data = ['template_key' => $this->templateKey];

        if ($this->customTitle !== null || $this->customBody !== null) {
            $data += ['title' => $this->customTitle, 'body' => $this->customBody];
        } else {
            $snapshot = NotificationText::render(
                $this->templateKey,
                $this->placeholders,
                $notifiable->preferred_language ?? config('app.locale'),
            );

            $data += ['params' => $this->placeholders] + $snapshot;
        }

        return $data + ['data' => $this->extraData];
    }
}
