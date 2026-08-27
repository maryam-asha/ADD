<?php

namespace App\Domain\Access\Services;

use App\Domain\Access\Enums\AccessEventChannel;
use App\Domain\Access\Enums\AccessEventType;
use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Enums\AccessSourceType;
use App\Domain\Access\Exceptions\LockAccessDeniedException;
use App\Domain\Access\Exceptions\TTLockException;
use App\Domain\Access\Models\AccessEvent;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Booking\Enums\BookingStatus;
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

        if ($userGrant && $this->isUsable($userGrant)) {
            return $userGrant;
        }

        $companyIds = $user->companies()->wherePivot('door_access_enabled', true)->pluck('companies.id');

        if ($companyIds->isEmpty()) {
            return null;
        }

        $companyGrant = $base()->where('grantee_type', OwnerType::Company)->whereIn('grantee_id', $companyIds)->first();

        return $companyGrant && $this->isUsable($companyGrant) ? $companyGrant : null;
    }

    /**
     * Defense-in-depth for a cancelled booking's grant (final-review C1) —
     * the primary fix is the scheduled RevokeAccessGrantsOnBookingCancellation
     * command, but a grant can still be `Activated` for a few minutes
     * between cancellation and the next run. `loadMissing` is a single
     * additional query per candidate grant (a no-op once already loaded),
     * never N+1 across candidates — this method runs at most twice per
     * unlock attempt (once for the user grant, once for a company grant).
     */
    private function isUsable(AccessGrant $grant): bool
    {
        // source_id is always set for a real booking-sourced grant
        // (PasscodeIssuanceService::issueForBooking() always passes
        // $booking->id) — null here means this grant isn't actually tied
        // to a real booking row, so there's nothing to check.
        if ($grant->source_type !== AccessSourceType::Booking || $grant->source_id === null) {
            return true;
        }

        $grant->loadMissing('booking');

        return $grant->booking?->status === BookingStatus::Confirmed;
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
