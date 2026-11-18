<?php

namespace Tests\Feature\Notification\Concerns;

use App\Domain\Identity\Enums\NotificationAudience;
use App\Domain\Identity\Models\InboxNotification;
use App\Domain\Identity\Models\User;
use App\Services\Notification\NotificationText;
use Illuminate\Support\Collection;

/**
 * What reached an account's inbox: the rows of one template, their params,
 * and their text as a reader in a given language sees it.
 */
trait ReadsNotices
{
    /** @return Collection<int, InboxNotification> */
    protected function notices(User|int $user, string $templateKey): Collection
    {
        return InboxNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user instanceof User ? $user->id : $user)
            ->get()
            ->filter(fn (InboxNotification $notice) => ($notice->data['template_key'] ?? null) === $templateKey)
            ->values();
    }

    /** @return Collection<int, InboxNotification> every account's rows of one template */
    protected function allNotices(string $templateKey): Collection
    {
        return InboxNotification::query()
            ->get()
            ->filter(fn (InboxNotification $notice) => ($notice->data['template_key'] ?? null) === $templateKey)
            ->values();
    }

    protected function assertNoticed(User|int $user, string $templateKey, NotificationAudience $audience = NotificationAudience::Member): InboxNotification
    {
        $notices = $this->notices($user, $templateKey);

        $this->assertCount(1, $notices, "Expected one [{$templateKey}] notice.");
        $this->assertSame($audience, $notices->first()->audience);

        return $notices->first();
    }

    protected function assertNotNoticed(User|int $user, string $templateKey): void
    {
        $this->assertCount(0, $this->notices($user, $templateKey), "Expected no [{$templateKey}] notice.");
    }

    /** @return array{title: string, body: string} */
    protected function noticeText(InboxNotification $notice, string $locale): array
    {
        return NotificationText::render($notice->data['template_key'], $notice->data['params'], $locale);
    }
}
