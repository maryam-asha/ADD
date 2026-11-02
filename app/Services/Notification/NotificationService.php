<?php

namespace App\Services\Notification;

use App\Domain\Identity\Enums\NotificationAudience;
use App\Domain\Identity\Enums\NotificationChannel;
use App\Domain\Identity\Enums\NotificationStatus;
use App\Domain\Identity\Models\DeviceToken;
use App\Domain\Identity\Models\NotificationLog;
use App\Domain\Identity\Models\User;
use App\Notifications\GeneralNotification;
use App\Services\Realtime\RealtimeBroadcaster;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * The one way a notification is sent. Delivery happens in the request — the
 * host runs no queue worker — and only once the surrounding transaction
 * commits, so a change that rolls back tells nobody; outside a transaction
 * it is immediate. A failed push or nudge is logged, never thrown.
 */
class NotificationService
{
    public function __construct(
        private readonly PushProvider $pushProvider,
        private readonly RealtimeBroadcaster $realtime,
    ) {}

    /**
     * Send a notification to a single user: a row in the inbox of its
     * audience, and — for a member — a push to every device, each in its own
     * language.
     */
    public function sendToUser(
        User $user,
        string $templateKey,
        array $placeholders = [],
        array $extraData = [],
        NotificationAudience $audience = NotificationAudience::Member,
    ): void {
        $this->ensureTemplate($templateKey);

        $this->deliver(
            $user,
            new GeneralNotification($templateKey, $placeholders, $extraData, audience: $audience),
            fn (string $locale) => NotificationText::render($templateKey, $placeholders, $locale),
            array_merge($extraData, ['template_key' => $templateKey]),
        );
    }

    /**
     * Send a notification to multiple users.
     */
    public function sendToUsers(
        Collection $users,
        string $templateKey,
        array $placeholders = [],
        array $extraData = [],
        NotificationAudience $audience = NotificationAudience::Member,
    ): void {
        foreach ($users as $user) {
            $this->sendToUser($user, $templateKey, $placeholders, $extraData, $audience);
        }
    }

    /**
     * Send a staff notice to every active account holding $permission —
     * through any role or directly, whatever the role is called — in the
     * dashboard. Never pick staff by role name.
     */
    public function sendToHolders(string $permission, string $templateKey, array $placeholders = [], array $extraData = []): void
    {
        try {
            $holders = User::permission($permission)->where('status', 'active')->get();
        } catch (PermissionDoesNotExist) {
            // permissions:sync has not run yet: nobody can hold it.
            return;
        }

        $this->sendToUsers($holders, $templateKey, $placeholders, $extraData, NotificationAudience::Staff);
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
     *
     * @param  array<string, string>  $title  keyed by locale
     * @param  array<string, string>  $body  keyed by locale
     */
    public function sendCustomToUser(User $user, array $title, array $body, array $extraData = []): void
    {
        $this->deliver(
            $user,
            new GeneralNotification('custom', [], $extraData, $title, $body),
            fn (string $locale) => ['title' => NotificationText::pick($title, $locale), 'body' => NotificationText::pick($body, $locale)],
            array_merge($extraData, ['template_key' => 'custom']),
        );
    }

    /**
     * The inbox row, the push (member audience only — the app is the
     * member's inbox), and the nudge to the reader's open screens, once the
     * caller's transaction has committed.
     *
     * @param  Closure(string): array{title: string, body: string}  $textFor  the push text in one locale
     */
    private function deliver(User $user, GeneralNotification $notification, Closure $textFor, array $pushData): void
    {
        DB::afterCommit(function () use ($user, $notification, $textFor, $pushData) {
            $user->notify($notification);

            if ($notification->audience === NotificationAudience::Member) {
                $this->sendPush($user, $textFor, $pushData);
            }

            $this->realtime->send(new NotificationCreated($user->id, $notification->audience));
        });
    }

    /**
     * A template that does not exist is a bug: it fails loudly everywhere
     * but production, where it is reported and the notice still goes out.
     */
    private function ensureTemplate(string $templateKey): void
    {
        if (NotificationText::exists($templateKey)) {
            return;
        }

        $missing = new LogicException("Notification template [{$templateKey}] is missing a title or body in some locale.");

        if (! app()->isProduction()) {
            throw $missing;
        }

        report($missing);
    }

    /**
     * Every device of the account, each in its own language: the one it was
     * last used in, else the account's.
     *
     * @param  Closure(string): array{title: string, body: string}  $textFor  the text in one locale
     */
    private function sendPush(User $user, Closure $textFor, array $data): void
    {
        $accountLanguage = $user->preferred_language ?? config('app.locale');

        $byLanguage = $user->deviceTokens()->get(['token', 'language'])
            ->groupBy(fn (DeviceToken $device) => $device->language ?? $accountLanguage)
            ->map(fn ($devices) => $devices->pluck('token')->all());

        if ($byLanguage->isEmpty() && ! empty($user->fcm_token)) {
            $byLanguage = collect([$accountLanguage => [$user->fcm_token]]);
        }

        foreach ($byLanguage as $language => $tokens) {
            $this->pushTo($user, $tokens, (string) $language, $textFor((string) $language), $data);
        }
    }

    /**
     * @param  list<string>  $tokens
     * @param  array{title: string, body: string}  $text
     */
    private function pushTo(User $user, array $tokens, string $language, array $text, array $data): void
    {
        ['title' => $title, 'body' => $body] = $text;

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
                    'language' => $language,
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
                'payload' => ['title' => $title, 'body' => $body, 'data' => $data, 'language' => $language],
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}
