<?php

namespace App\Services\Notification;

use App\Domain\Identity\Enums\DeviceSurface;
use App\Domain\Identity\Enums\NotificationAudience;
use App\Domain\Identity\Enums\NotificationChannel;
use App\Domain\Identity\Enums\NotificationStatus;
use App\Domain\Identity\Models\DeviceToken;
use App\Domain\Identity\Models\NotificationLog;
use App\Domain\Identity\Models\User;
use App\Notifications\GeneralNotification;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * The one way a notification is sent. Delivery happens in the request — the
 * host runs no queue worker — and only once the surrounding transaction
 * commits, so a change that rolls back tells nobody; outside a transaction
 * it is immediate. A failed push is logged, never thrown.
 */
class NotificationService
{
    public function __construct(
        private readonly PushProvider $pushProvider,
    ) {}

    /**
     * Send a notification to a single user: a row in the inbox of its
     * audience, and a push to every device of that audience — the app for a
     * member, the dashboard browsers for staff and provider — each in its
     * own language.
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
     * Send once per person and $dedupeKey — for reminders, which find the
     * same subject on every run. The key is claimed first, and only a new
     * claim sends, so two runs at once cannot both send. Returns whether it
     * was sent.
     */
    public function sendToUserOnce(
        User $user,
        string $dedupeKey,
        string $templateKey,
        array $placeholders = [],
        array $extraData = [],
        NotificationAudience $audience = NotificationAudience::Member,
    ): bool {
        $claimed = DB::table('notification_claims')->insertOrIgnore([
            'user_id' => $user->id,
            'dedupe_key' => $dedupeKey,
            'created_at' => now(),
        ]);

        if ($claimed === 0) {
            return false;
        }

        $this->sendToUser($user, $templateKey, $placeholders, $extraData, $audience);

        return true;
    }

    /**
     * sendToUserOnce() for each of $users.
     *
     * @return int how many were sent
     */
    public function sendToUsersOnce(
        Collection $users,
        string $dedupeKey,
        string $templateKey,
        array $placeholders = [],
        array $extraData = [],
        NotificationAudience $audience = NotificationAudience::Member,
    ): int {
        return $users->filter(
            fn (User $user) => $this->sendToUserOnce($user, $dedupeKey, $templateKey, $placeholders, $extraData, $audience),
        )->count();
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
     * sendToHolders(), once per holder and $dedupeKey — a staff reminder
     * found on every run (the overdue-invoice digest).
     */
    public function sendToHoldersOnce(string $permission, string $dedupeKey, string $templateKey, array $placeholders = [], array $extraData = []): void
    {
        try {
            $holders = User::permission($permission)->where('status', 'active')->get();
        } catch (PermissionDoesNotExist) {
            return;
        }

        $this->sendToUsersOnce($holders, $dedupeKey, $templateKey, $placeholders, $extraData, NotificationAudience::Staff);
    }

    /**
     * Broadcast a notification to every active user.
     */
    public function broadcast(string $templateKey, array $placeholders = [], array $extraData = []): void
    {
        $users = User::where('status', 'active')
            ->where(function ($query) {
                $query->whereNotNull('fcm_token')
                    ->orWhereHas('deviceTokens', fn ($devices) => $devices->where('surface', DeviceSurface::App));
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
     * The inbox row and the push — a member's to the app, a staff or
     * provider notice to the account's dashboard browsers — once the
     * caller's transaction has committed.
     *
     * @param  Closure(string): array{title: string, body: string}  $textFor  the push text in one locale
     */
    private function deliver(User $user, GeneralNotification $notification, Closure $textFor, array $pushData): void
    {
        DB::afterCommit(function () use ($user, $notification, $textFor, $pushData) {
            // Set here, not by the notifier, so the push can name the inbox
            // row a click on it opens.
            $notification->id ??= (string) Str::uuid();
            $user->notify($notification);

            if ($notification->audience === NotificationAudience::Member) {
                $this->sendPush($user, $textFor, $pushData);
            } else {
                $this->sendDashboardPush($user, $textFor, $pushData + [
                    'notification_id' => $notification->id,
                    'audience' => $notification->audience->value,
                ]);
            }
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
     * Every app device of the account, each in its own language: the one it
     * was last used in, else the account's.
     *
     * @param  Closure(string): array{title: string, body: string}  $textFor  the text in one locale
     */
    private function sendPush(User $user, Closure $textFor, array $data): void
    {
        $byLanguage = $this->tokensByLanguage($user, DeviceSurface::App);

        if ($byLanguage->isEmpty() && ! empty($user->fcm_token)) {
            $byLanguage = collect([$this->accountLanguage($user) => [$user->fcm_token]]);
        }

        foreach ($byLanguage as $language => $tokens) {
            $this->pushTo($user, $tokens, (string) $language, $textFor((string) $language), $data);
        }
    }

    /**
     * Every dashboard browser of the account, each in its own language, as a
     * data-only message: the browser shows it only when no dashboard tab is
     * in view, where the bell already has it. FCM data values are strings.
     *
     * @param  Closure(string): array{title: string, body: string}  $textFor  the text in one locale
     */
    private function sendDashboardPush(User $user, Closure $textFor, array $data): void
    {
        $data = array_map(
            fn ($value) => is_scalar($value) ? (string) $value : (string) json_encode($value),
            array_filter($data, fn ($value) => $value !== null),
        );

        foreach ($this->tokensByLanguage($user, DeviceSurface::Dashboard) as $language => $tokens) {
            $text = $textFor((string) $language);
            $message = ['title' => $text['title'], 'body' => $text['body']] + $data;

            try {
                $results = $this->pushProvider->sendDataToDevices($tokens, $message);
                $this->logPush($user, $data, in_array(true, $results, true) ? NotificationStatus::Sent : NotificationStatus::Failed, [
                    'title' => $text['title'],
                    'body' => $text['body'],
                    'data' => $data,
                    'device_count' => count($tokens),
                    'language' => (string) $language,
                    'surface' => DeviceSurface::Dashboard->value,
                ]);
            } catch (\Throwable $e) {
                Log::error('[NotificationService] Dashboard push failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);

                $this->logPush($user, $data, NotificationStatus::Failed, [
                    'title' => $text['title'],
                    'body' => $text['body'],
                    'data' => $data,
                    'language' => (string) $language,
                    'surface' => DeviceSurface::Dashboard->value,
                ], $e->getMessage());
            }
        }
    }

    /**
     * @return BaseCollection<string, list<string>>
     */
    private function tokensByLanguage(User $user, DeviceSurface $surface): BaseCollection
    {
        $accountLanguage = $this->accountLanguage($user);

        return $user->deviceTokens()->where('surface', $surface)->get(['token', 'language'])
            ->groupBy(fn (DeviceToken $device) => $device->language ?? $accountLanguage)
            ->map(fn ($devices) => $devices->pluck('token')->all());
    }

    private function accountLanguage(User $user): string
    {
        return $user->preferred_language ?? config('app.locale');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function logPush(User $user, array $data, NotificationStatus $status, array $payload, ?string $error = null): void
    {
        NotificationLog::create([
            'user_id' => $user->id,
            'channel' => NotificationChannel::Push,
            'template_key' => $data['template_key'] ?? 'custom',
            'status' => $status,
            'payload' => $payload,
            'error_message' => $error,
        ]);
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
