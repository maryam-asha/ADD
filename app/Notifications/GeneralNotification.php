<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class GeneralNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $templateKey,
        private readonly array $placeholders = [],
        private readonly array $extraData = [],
        private readonly ?string $customTitle = null,
        private readonly ?string $customBody = null,
    ) {
        $this->onQueue(config('notifications.queue', 'notifications'));
    }

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        $locale = $notifiable->preferred_language ?? config('app.locale');

        return [
            'template_key' => $this->templateKey,
            'title' => $this->customTitle ?? __('notifications.'.$this->templateKey.'.title', $this->placeholders, $locale),
            'body' => $this->customBody ?? __('notifications.'.$this->templateKey.'.body', $this->placeholders, $locale),
            'data' => $this->extraData,
        ];
    }
}
