<?php

namespace App\Services\Notification;

use App\Domain\Identity\Enums\NotificationChannel;
use App\Domain\Identity\Enums\NotificationStatus;
use App\Domain\Identity\Models\NotificationLog;
use App\Domain\Identity\Models\User;
use App\Notifications\GeneralNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function __construct(private readonly PushProvider $pushProvider) {}

    /**
     * Send a notification to a single user — locale is resolved automatically
     * from the user's preferred_language.
     */
    public function sendToUser(User $user, string $templateKey, array $placeholders = [], array $extraData = []): void
    {
        $locale = $user->preferred_language ?? config('app.locale');

        $title = __('notifications.'.$templateKey.'.title', $placeholders, $locale);
        $body = __('notifications.'.$templateKey.'.body', $placeholders, $locale);

        $payloadData = array_merge($extraData, ['template_key' => $templateKey]);

        // In-app (database channel)
        $user->notify(new GeneralNotification($templateKey, $placeholders, $extraData));

        // Push (FCM to all user devices)
        $this->sendPush($user, $title, $body, $payloadData);
    }

    /**
     * Send a notification to multiple users — groups by locale to minimize
     * translation lookups, then dispatches per-user.
     */
    public function sendToUsers(Collection $users, string $templateKey, array $placeholders = [], array $extraData = []): void
    {
        $grouped = $users->groupBy(fn (User $u) => $u->preferred_language ?? config('app.locale'));
        $payloadData = array_merge($extraData, ['template_key' => $templateKey]);

        foreach ($grouped as $locale => $localeUsers) {
            $title = __('notifications.'.$templateKey.'.title', $placeholders, $locale);
            $body = __('notifications.'.$templateKey.'.body', $placeholders, $locale);

            foreach ($localeUsers as $user) {
                $user->notify(new GeneralNotification($templateKey, $placeholders, $extraData));
                $this->sendPush($user, $title, $body, $payloadData);
            }
        }
    }

    /**
     * Broadcast a notification to every active user.
     */
    public function broadcast(string $templateKey, array $placeholders = [], array $extraData = []): void
    {
        $users = User::where('status', 'active')
            ->where(function ($query) {
                $query->whereNotNull('fcm_token')
                    ->orWhereHas('deviceTokens');
            })
            ->get();

        $this->sendToUsers($users, $templateKey, $placeholders, $extraData);
    }

    /**
     * Send a custom (non-template) notification — used by admin broadcast
     * where the title/body are provided directly per locale.
     */
    public function sendCustomToUser(User $user, string $title, string $body, array $extraData = []): void
    {
        $user->notify(new GeneralNotification('custom', [], $extraData, $title, $body));
        $this->sendPush($user, $title, $body, array_merge($extraData, ['template_key' => 'custom']));
    }

    private function sendPush(User $user, string $title, string $body, array $data): void
    {
        $tokens = $user->deviceTokens()->pluck('token')->all();
        if (empty($tokens) && ! empty($user->fcm_token)) {
            $tokens = [$user->fcm_token];
        }

        if (empty($tokens)) {
            return;
        }

        try {
            $results = $this->pushProvider->sendToDevices($tokens, $title, $body, $data);
            $hasSuccess = in_array(true, $results, true);

            NotificationLog::create([
                'user_id' => $user->id,
                'channel' => NotificationChannel::Push,
                'template_key' => $data['template_key'] ?? 'custom',
                'status' => $hasSuccess ? NotificationStatus::Sent : NotificationStatus::Failed,
                'payload' => [
                    'title' => $title,
                    'body' => $body,
                    'data' => $data,
                    'device_count' => count($tokens),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('[NotificationService] Push failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            NotificationLog::create([
                'user_id' => $user->id,
                'channel' => NotificationChannel::Push,
                'template_key' => $data['template_key'] ?? 'custom',
                'status' => NotificationStatus::Failed,
                'payload' => ['title' => $title, 'body' => $body, 'data' => $data],
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}
