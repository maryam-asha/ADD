<?php

namespace App\Services\Notification;

use App\Domain\Identity\Enums\DeviceSurface;
use App\Domain\Identity\Models\DeviceToken;
use App\Domain\Identity\Models\User;
use App\Services\Auth\TokenPair;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * The one place a device's FCM token is registered or forgotten. A token is
 * tied to the session (access token) it was registered on, so whatever ends a
 * session — logout, password reset, block — also stops pushes to that device,
 * and a token belongs to one account at a time: a phone that signs in as
 * someone else stops receiving the previous account's notifications.
 *
 * `users.fcm_token` is the legacy single-device column NotificationService
 * still falls back to; it is kept pointing at the latest remaining device.
 */
class DeviceTokenService
{
    /**
     * What the app may report about the device alongside its token. A field it
     * leaves out keeps whatever was stored before.
     */
    public const DETAILS = ['platform', 'language', 'device_id', 'app_version', 'build_number', 'os_version', 'device_model'];

    /**
     * A dashboard browser's token has no session of its own to follow (the
     * dashboard signs in with a cookie): the browser forgets it at sign-out,
     * and it never becomes the legacy `users.fcm_token`, which is the app's.
     *
     * @param  array<string, mixed>  $details  any of DETAILS
     */
    public function register(User $user, string $token, array $details = [], ?int $accessTokenId = null, DeviceSurface $surface = DeviceSurface::App): void
    {
        $details = array_filter(Arr::only($details, self::DETAILS), fn ($value) => $value !== null && $value !== '');
        $deviceId = $details['device_id'] ?? null;

        DB::transaction(function () use ($user, $token, $details, $deviceId, $accessTokenId, $surface) {
            // One account per token and per install: whoever held either before
            // stops getting pushes on this device.
            $elsewhere = DeviceToken::where('user_id', '!=', $user->id)
                ->where(fn ($query) => $query
                    ->where('token', $token)
                    ->when($deviceId, fn ($query) => $query->orWhere('device_id', $deviceId)));

            $previousOwners = (clone $elsewhere)->pluck('user_id');
            $elsewhere->delete();

            User::whereIn('id', $previousOwners)
                ->orWhere(fn ($query) => $query->where('fcm_token', $token)->whereKeyNot($user->id))
                ->get()
                ->each(fn (User $owner) => $this->syncLegacyColumn($owner));

            // The install's own row when it has one, so a token Firebase
            // rotated replaces the old one rather than sitting beside it.
            $device = $deviceId ? $user->deviceTokens()->where('surface', $surface)->where('device_id', $deviceId)->first() : null;
            $device ??= $user->deviceTokens()->where('token', $token)->first();

            // Any other row of this account still holding the token (unique per account).
            $user->deviceTokens()
                ->where('token', $token)
                ->when($device, fn ($query) => $query->whereKeyNot($device->getKey()))
                ->delete();

            ($device ?? $user->deviceTokens()->make())
                ->fill(['token' => $token, 'surface' => $surface, 'last_used_at' => now()] + $details + array_filter(['access_token_id' => $accessTokenId]))
                ->save();

            if ($surface === DeviceSurface::App) {
                $user->forceFill(['fcm_token' => $token])->save();
            }
        });
    }

    /**
     * The token an endpoint that opened a session was sent, tied to that
     * session. Nothing to do when the app sent none.
     *
     * @param  array<string, mixed>  $details  any of DETAILS
     */
    public function registerOnSession(TokenPair $pair, ?string $token, array $details = []): void
    {
        if ($token === null || $token === '') {
            return;
        }

        $this->register($pair->user, $token, $details, $pair->accessTokenId);
    }

    public function forget(User $user, string $token, DeviceSurface $surface = DeviceSurface::App): void
    {
        $user->deviceTokens()->where('token', $token)->where('surface', $surface)->delete();

        if ($surface === DeviceSurface::App) {
            $this->syncLegacyColumn($user);
        }
    }

    /**
     * Every device of the account, or only those of one surface: the app
     * forgetting its own leaves the dashboard browsers alone.
     */
    public function forgetAll(User $user, ?DeviceSurface $surface = null): void
    {
        $user->deviceTokens()->when($surface, fn ($query) => $query->where('surface', $surface))->delete();

        if ($surface !== DeviceSurface::Dashboard) {
            $user->forceFill(['fcm_token' => null])->save();
        }
    }

    /**
     * The devices of one ended session.
     */
    public function forgetSession(int $accessTokenId): void
    {
        $tokens = DeviceToken::with('user')->where('access_token_id', $accessTokenId)->get();

        DeviceToken::whereKey($tokens->modelKeys())->delete();

        $tokens->pluck('user')->filter()->unique('id')->each(fn (User $user) => $this->syncLegacyColumn($user));
    }

    /**
     * Every device but the one on the session that is staying signed in. Rows
     * not tied to any session go too — they cannot be shown to belong to it,
     * and the app registers its token again on its next launch.
     */
    public function forgetAllExcept(User $user, int $accessTokenId): void
    {
        $user->deviceTokens()
            ->where(fn ($query) => $query
                ->whereNull('access_token_id')
                ->orWhere('access_token_id', '!=', $accessTokenId))
            ->delete();

        $this->syncLegacyColumn($user);
    }

    /**
     * The owner switched language on the device of this session: its pushes
     * follow.
     */
    public function setSessionLanguage(int $accessTokenId, string $language): void
    {
        DeviceToken::where('access_token_id', $accessTokenId)->update(['language' => $language]);
    }

    /**
     * A refresh replaces the access token; the device stays the same.
     */
    public function moveSession(int $fromAccessTokenId, int $toAccessTokenId): void
    {
        DeviceToken::where('access_token_id', $fromAccessTokenId)->update(['access_token_id' => $toAccessTokenId]);
    }

    private function syncLegacyColumn(User $user): void
    {
        $user->forceFill([
            'fcm_token' => $user->deviceTokens()->where('surface', DeviceSurface::App)->latest('id')->value('token'),
        ])->save();
    }
}
