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
use App\Domain\Booking\Enums\SessionStartSource;
use App\Domain\Booking\Services\AccessArrivalCheckIn;
use App\Domain\Foundation\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;

/**
 * The one read path for the new QR-scan channel — resolves qr_value to a
 * lock, finds an activated-and-in-window grant for the scanning user (or
 * a company they belong to with door_access_enabled), calls
 * TTLockClient::remoteUnlock() server-side, and logs one access_events
 * row either way. docs/decisions/qr-lock-unlock.md §4.
 *
 * A member need not pass the desk first (2026-10-28): their own booking's
 * code still waiting on reception (`issued`) opens the door from the app
 * while the booking runs — from its start, as on the keypad, since before
 * it the room may still be someone else's — and that first open activates
 * the code. Reception activating it stays the other way in.
 */
class UnlockService
{
    public function __construct(
        private readonly TTLockClient $ttlock,
        private readonly AccessArrivalCheckIn $arrivals,
    ) {}

    public function unlock(User $user, string $qrValue): void
    {
        $lock = Device::where('qr_value', $qrValue)->where('type', 'lock')->first();

        if (! $lock) {
            throw new LockAccessDeniedException('api.access.lock_not_found', 404);
        }

        $grant = $this->activeGrantFor($user, $lock) ?? $this->runningBookingGrantFor($user, $lock);

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

        // Only once the door has opened: a lock that did not (gateway down)
        // leaves the code as it was. Conditional on still being `issued`, so
        // the desk activating it in the same instant activates it once.
        if ($grant->status === AccessGrantStatus::Issued) {
            AccessGrant::query()
                ->whereKey($grant->id)
                ->where('status', AccessGrantStatus::Issued)
                ->update(['status' => AccessGrantStatus::Activated, 'activated_at' => now()]);
        }

        $this->logEvent($lock, $grant, AccessEventType::Unlock, $user);

        // A booked room has no kiosk scan: opening its door is the arrival.
        $this->arrivals->forGrant($grant, SessionStartSource::Door);
    }

    /**
     * The member's own booking code the desk has not activated yet, while the
     * booking runs: confirmed, started, not ended, and not past the time the
     * code had to be activated by (access:expire-unactivated-grants is about
     * to end it).
     */
    private function runningBookingGrantFor(User $user, Device $lock): ?AccessGrant
    {
        $now = now();

        return AccessGrant::query()
            ->where('lock_id', $lock->id)
            ->where('status', AccessGrantStatus::Issued)
            ->where('grantee_type', OwnerType::User)
            ->where('grantee_id', $user->id)
            ->where('source_type', AccessSourceType::Booking)
            ->whereNotNull('source_id')
            ->where('must_activate_by', '>=', $now)
            ->whereHas('booking', fn ($booking) => $booking
                ->where('status', BookingStatus::Confirmed)
                ->where('start_at', '<=', $now)
                ->where('end_at', '>', $now))
            ->first();
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

        // A checkout completes the booking, but the room stays the member's
        // until end_at — the grant's own period still bounds it.
        return in_array($grant->booking?->status, [BookingStatus::Confirmed, BookingStatus::Completed], true);
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
