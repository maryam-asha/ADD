<?php

namespace App\Domain\Access\Services;

use App\Domain\Access\Exceptions\TTLockException;
use App\Domain\Foundation\Models\Device;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The only class in this app that talks to TTLock's Cloud API V3. Every
 * verified endpoint signature and quirk this implements is recorded in
 * docs/decisions/qr-lock-unlock.md's "TTLock verification findings"
 * section — several diverge from the originally-assumed shapes (e.g.
 * addType belongs to keyboardPwd/add, not keyboardPwd/get; deleteType
 * defaults to Bluetooth and must be set to 2 for gateway deletion).
 */
class TTLockClient
{
    private const TOKEN_CACHE_KEY = 'ttlock:oauth_token';

    private const KEYBOARD_PWD_VERSION_V4 = 4;

    private const PASSCODE_TYPE_PERIOD = 3;

    private const DELETE_TYPE_GATEWAY = 2;

    public function addPeriodPasscode(Device $lock, \DateTimeInterface $startsAt, \DateTimeInterface $endsAt): array
    {
        $response = $this->callV3('/v3/keyboardPwd/get', [
            'lockId' => $lock->external_ref,
            'keyboardPwdVersion' => self::KEYBOARD_PWD_VERSION_V4,
            'keyboardPwdType' => self::PASSCODE_TYPE_PERIOD,
            'startDate' => $startsAt->getTimestamp() * 1000,
            'endDate' => $endsAt->getTimestamp() * 1000,
        ]);

        if (! isset($response['keyboardPwd'], $response['keyboardPwdId'])) {
            $this->assertSuccess($response);
            throw TTLockException::vendorError(-1, 'TTLock response missing keyboardPwd/keyboardPwdId');
        }

        return [
            'passcode' => (string) $response['keyboardPwd'],
            'vendor_passcode_id' => (int) $response['keyboardPwdId'],
        ];
    }

    public function deletePasscode(Device $lock, ?int $vendorPasscodeId): void
    {
        $this->assertSuccess($this->callV3('/v3/keyboardPwd/delete', [
            'lockId' => $lock->external_ref,
            'keyboardPwdId' => $vendorPasscodeId,
            'deleteType' => self::DELETE_TYPE_GATEWAY,
        ]));
    }

    public function remoteUnlock(Device $lock): void
    {
        $this->assertSuccess($this->callV3('/v3/lock/unlock', [
            'lockId' => $lock->external_ref,
        ]));
    }

    private function assertSuccess(array $response): void
    {
        if (! array_key_exists('errcode', $response)) {
            throw TTLockException::vendorError(-1, 'TTLock response missing errcode');
        }

        $errcode = (int) $response['errcode'];

        if ($errcode === 0) {
            return;
        }

        throw match ($errcode) {
            -2012 => TTLockException::gatewayOffline(),
            -4043 => TTLockException::remoteUnlockDisabled(),
            20001, 20002, 20003, 20004 => TTLockException::lockNotFound(),
            default => TTLockException::vendorError($errcode, (string) ($response['errmsg'] ?? 'unknown TTLock error')),
        };
    }

    private function callV3(string $path, array $params): array
    {
        $params['clientId'] = config('services.ttlock.client_id');
        $params['accessToken'] = $this->accessToken();
        // TTLock rejects any request whose `date` is more than 5 minutes
        // from its own clock (errcode 80000) — always the real current time.
        $params['date'] = (int) (microtime(true) * 1000);

        try {
            $response = Http::asForm()
                ->baseUrl(config('services.ttlock.base_url'))
                ->timeout(10)
                ->post($path, $params);
        } catch (ConnectionException $e) {
            throw TTLockException::networkError($e->getMessage());
        }

        if (! $response->successful()) {
            throw TTLockException::vendorError($response->status(), "HTTP {$response->status()} from TTLock");
        }

        return $response->json() ?? [];
    }

    private function accessToken(): string
    {
        $bundle = Cache::get(self::TOKEN_CACHE_KEY);

        if (is_array($bundle) && ($bundle['expires_at'] ?? 0) > time()) {
            return $bundle['access_token'];
        }

        if (is_array($bundle) && isset($bundle['refresh_token'])) {
            try {
                $bundle = $this->refreshToken($bundle['refresh_token']);
            } catch (TTLockException) {
                $bundle = $this->fetchToken();
            }
        } else {
            $bundle = $this->fetchToken();
        }

        // Cached longer than the access token's own lifetime so the
        // refresh_token survives in cache past the access token's expiry —
        // otherwise every expiry would force a full password re-auth
        // instead of a refresh.
        Cache::put(self::TOKEN_CACHE_KEY, $bundle, now()->addDays(89));

        return $bundle['access_token'];
    }

    private function fetchToken(): array
    {
        return $this->requestToken([
            'client_id' => config('services.ttlock.client_id'),
            'client_secret' => config('services.ttlock.client_secret'),
            'username' => config('services.ttlock.username'),
            'password' => md5((string) config('services.ttlock.password')),
        ]);
    }

    private function refreshToken(string $refreshToken): array
    {
        return $this->requestToken([
            'client_id' => config('services.ttlock.client_id'),
            'client_secret' => config('services.ttlock.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    private function requestToken(array $params): array
    {
        try {
            $response = Http::asForm()
                ->baseUrl(config('services.ttlock.base_url'))
                ->timeout(10)
                ->post('/oauth2/token', $params);
        } catch (ConnectionException $e) {
            throw TTLockException::networkError($e->getMessage());
        }

        $body = $response->json() ?? [];

        if (! $response->successful() || ! isset($body['access_token'])) {
            $code = (int) ($body['errcode'] ?? -1);

            throw in_array($code, [10000, 10001, 10007, 10011], true)
                ? TTLockException::invalidCredentials()
                : TTLockException::vendorError($code, (string) ($body['errmsg'] ?? 'TTLock token request failed'));
        }

        return [
            'access_token' => $body['access_token'],
            'refresh_token' => $body['refresh_token'] ?? null,
            'expires_at' => time() + (int) ($body['expires_in'] ?? 0) - 60,
        ];
    }
}
