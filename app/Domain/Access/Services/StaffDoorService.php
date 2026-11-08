<?php

namespace App\Domain\Access\Services;

use App\Domain\Access\Enums\AccessEventChannel;
use App\Domain\Access\Enums\AccessEventType;
use App\Domain\Access\Exceptions\LockAccessDeniedException;
use App\Domain\Access\Exceptions\TTLockException;
use App\Domain\Access\Models\AccessEvent;
use App\Domain\Foundation\Models\Device;
use App\Domain\Identity\Models\User;

/**
 * Staff opening or locking any lock from the dashboard — letting someone in
 * whose code does not work, or closing a door left open. No grant is needed
 * (the permission is the grant); every attempt is logged against the staff
 * member, through the gateway like the member's own app buttons.
 */
class StaffDoorService
{
    public function __construct(private readonly TTLockClient $ttlock) {}

    public function unlock(Device $lock, User $staff): void
    {
        $this->act($lock, $staff, AccessEventType::Unlock, fn () => $this->ttlock->remoteUnlock($lock), 'api.access.unlock_failed');
    }

    public function lock(Device $lock, User $staff): void
    {
        $this->act($lock, $staff, AccessEventType::Lock, fn () => $this->ttlock->remoteLock($lock), 'api.access.lock_failed');
    }

    private function act(Device $lock, User $staff, AccessEventType $done, callable $call, string $failedKey): void
    {
        if ($lock->type !== 'lock') {
            throw new LockAccessDeniedException('api.admin.device_not_a_lock', 422);
        }

        if (blank($lock->external_ref)) {
            throw new LockAccessDeniedException('api.access.lock_not_linked', 422);
        }

        try {
            $call();
        } catch (TTLockException $e) {
            $this->log($lock, $staff, AccessEventType::FailedAttempt);

            throw new LockAccessDeniedException(match ($e->vendorErrorCode) {
                -2012 => 'api.access.staff_gateway_offline',
                -4043 => 'api.access.staff_remote_disabled',
                default => $failedKey,
            }, 503);
        }

        $this->log($lock, $staff, $done);
    }

    private function log(Device $lock, User $staff, AccessEventType $type): void
    {
        AccessEvent::create([
            'device_id' => $lock->id,
            'access_grant_id' => null,
            'event_type' => $type,
            'channel' => AccessEventChannel::Dashboard,
            'actor_user_id' => $staff->id,
            'occurred_at' => now(),
        ]);
    }
}
