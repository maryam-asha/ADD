<?php

namespace App\Domain\Access\Services;

use App\Domain\Access\Enums\AccessEventChannel;
use App\Domain\Access\Enums\AccessEventType;
use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Exceptions\LockAccessDeniedException;
use App\Domain\Access\Exceptions\TTLockException;
use App\Domain\Access\Models\AccessEvent;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Foundation\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;

/**
 * The one read path for the new QR-scan channel — resolves qr_value to a
 * lock, finds an activated-and-in-window grant for the scanning user (or
 * a company they belong to with door_access_enabled), calls
 * TTLockClient::remoteUnlock() server-side, and logs one access_events
 * row either way. docs/decisions/qr-lock-unlock.md §4.
 */
class UnlockService
{
    public function __construct(private readonly TTLockClient $ttlock) {}

    public function unlock(User $user, string $qrValue): void
    {
        $lock = Device::where('qr_value', $qrValue)->where('type', 'lock')->first();

        if (! $lock) {
            throw new LockAccessDeniedException('api.access.lock_not_found', 404);
        }

        $grant = $this->activeGrantFor($user, $lock);

        if (! $grant) {
            $this->logEvent($lock, null, AccessEventType::FailedAttempt, $user);
            throw new LockAccessDeniedException('api.access.no_active_grant', 403);
        }

        try {
            $this->ttlock->remoteUnlock($lock);
        } catch (TTLockException $e) {
            $this->logEvent($lock, $grant, AccessEventType::FailedAttempt, $user);
            throw new LockAccessDeniedException(
                $e->vendorErrorCode === -2012 ? 'api.access.gateway_offline' : 'api.access.unlock_failed',
                503,
            );
        }

        $this->logEvent($lock, $grant, AccessEventType::Unlock, $user);
    }

    private function activeGrantFor(User $user, Device $lock): ?AccessGrant
    {
        $now = now();
        $base = fn () => AccessGrant::query()
            ->where('lock_id', $lock->id)
            ->where('status', AccessGrantStatus::Activated)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', $now));

        $userGrant = $base()->where('grantee_type', OwnerType::User)->where('grantee_id', $user->id)->first();

        if ($userGrant) {
            return $userGrant;
        }

        $companyIds = $user->companies()->wherePivot('door_access_enabled', true)->pluck('companies.id');

        if ($companyIds->isEmpty()) {
            return null;
        }

        return $base()->where('grantee_type', OwnerType::Company)->whereIn('grantee_id', $companyIds)->first();
    }

    private function logEvent(Device $lock, ?AccessGrant $grant, AccessEventType $type, User $actor): void
    {
        AccessEvent::create([
            'device_id' => $lock->id,
            'access_grant_id' => $grant?->id,
            'event_type' => $type,
            'channel' => AccessEventChannel::QrScan,
            'actor_user_id' => $actor->id,
            'occurred_at' => now(),
        ]);
    }
}
