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
 * code still waiting on reception (`issued`) opens the door from the app,
 * and that first open activates the code and checks the booking in.
 * Reception activating it stays the other way in.
 *
 * A booking's door opens to the scan only within BookingDoorWindow (product
 * owner, 2026-11-08): from the early check-in allowance before it starts —
 * activated or not, so a code the desk activated early opens nothing sooner —
 * until the overrun grace after it ends, for a member who came; one who never
 * did is not let in once it has ended. The app's lock/unlock buttons are not
 * this: they need the booking checked in (BookingDoorService).
 */
class UnlockService
{
    public function __construct(
        private readonly TTLockClient $ttlock,
        private readonly AccessArrivalCheckIn $arrivals,
        private readonly BookingDoorWindow $window,
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
     * The member's own booking code the desk has not activated yet: the scan
     * is their arrival, so the booking is confirmed, within its door window
     * and not ended yet (a member who never came is not let in afterwards),
     * and the code is not past the time it had to be activated by
     * (access:expire-unactivated-grants is about to end it).
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
                ->where('end_at', '>', $now))
            ->with('booking.space')
            ->get()
            ->first(fn (AccessGrant $grant) => $this->window->contains($grant->booking, $now));
    }

    private function activeGrantFor(User $user, Device $lock): ?AccessGrant
    {
        $base = fn () => AccessGrant::query()
            ->where('lock_id', $lock->id)
            ->where('status', AccessGrantStatus::Activated);

        $userGrant = $base()->where('grantee_type', OwnerType::User)->where('grantee_id', $user->id)
            ->with('booking.space')
            ->get()
            ->first(fn (AccessGrant $grant) => $this->isUsable($grant));

        if ($userGrant !== null) {
            return $userGrant;
        }

        $companyIds = $user->companies()->wherePivot('door_access_enabled', true)->pluck('companies.id');

        if ($companyIds->isEmpty()) {
            return null;
        }

        return $base()->where('grantee_type', OwnerType::Company)->whereIn('grantee_id', $companyIds)
            ->get()
            ->first(fn (AccessGrant $grant) => $this->isUsable($grant));
    }

    /**
     * A booking's code opens its door only within BookingDoorWindow, and only
     * while the booking stands — confirmed, or completed by a checkout, the
     * room still the member's through the grace (a cancelled booking's code
     * may still be `activated` for a few minutes before
     * RevokeAccessGrantsOnBookingCancellation reaches it). Past the end it
     * opens only for a member who came: the grace is for leaving, not
     * arriving. Any other code (an office's, or one tied to no booking) runs
     * to its own expires_at.
     */
    private function isUsable(AccessGrant $grant): bool
    {
        $now = now();

        if ($grant->source_type !== AccessSourceType::Booking || $grant->source_id === null) {
            return $grant->expires_at === null || $grant->expires_at->greaterThanOrEqualTo($now);
        }

        $booking = $grant->booking;

        if ($booking === null || ! in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::Completed], true)) {
            return false;
        }

        if ($now->greaterThanOrEqualTo($booking->end_at) && $booking->checked_in_at === null) {
            return false;
        }

        return $this->window->contains($booking, $now);
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
