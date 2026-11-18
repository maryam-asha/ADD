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
use App\Domain\Booking\Models\Booking;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The door buttons on a booked room's live card: open or lock the booked
 * room's door from the app, no sticker scan. Only once the booking is
 * checked in and until it closes — arriving stays the desk's or the
 * sticker's job, so these never check anything in. The door is the lock
 * the booking's own code was issued on; nothing about it reaches the
 * member but the outcome.
 */
class BookingDoorService
{
    public function __construct(private readonly TTLockClient $ttlock) {}

    /** Whether the live card should offer the buttons at all. */
    public function available(Booking $booking): bool
    {
        return $this->isLive($booking) && $this->grantFor($booking) !== null;
    }

    public function unlock(User $member, Booking $booking): void
    {
        $grant = $this->usableGrant($booking);

        try {
            $this->ttlock->remoteUnlock($grant->lock);
        } catch (TTLockException $e) {
            $this->log($grant, AccessEventType::FailedAttempt, $member);
            throw new LockAccessDeniedException(
                $e->vendorErrorCode === -2012 ? 'api.access.gateway_offline' : 'api.access.unlock_failed',
                503,
            );
        }

        // A code the desk checked in without activating is live from here,
        // as when the sticker opens it. Conditional, so it activates once.
        if ($grant->status === AccessGrantStatus::Issued) {
            AccessGrant::query()
                ->whereKey($grant->id)
                ->where('status', AccessGrantStatus::Issued)
                ->update(['status' => AccessGrantStatus::Activated, 'activated_at' => now()]);
        }

        $this->log($grant, AccessEventType::Unlock, $member);
    }

    public function lock(User $member, Booking $booking): void
    {
        $grant = $this->usableGrant($booking);

        try {
            $this->ttlock->remoteLock($grant->lock);
        } catch (TTLockException $e) {
            $this->log($grant, AccessEventType::FailedAttempt, $member);
            throw new LockAccessDeniedException(match ($e->vendorErrorCode) {
                -2012 => 'api.access.lock_gateway_offline',
                -4043 => 'api.access.remote_lock_unsupported',
                default => 'api.access.lock_failed',
            }, 503);
        }

        $this->log($grant, AccessEventType::Lock, $member);
    }

    private function usableGrant(Booking $booking): AccessGrant
    {
        if (! $this->isLive($booking)) {
            throw new LockAccessDeniedException('api.access.door_requires_check_in', 422);
        }

        $grant = $this->grantFor($booking);

        // No code means no lock row to log the attempt against.
        if ($grant === null) {
            throw new LockAccessDeniedException('api.access.door_unavailable', 422);
        }

        return $grant;
    }

    /** The live card's `active` state: a room checked in and not closed. */
    private function isLive(Booking $booking): bool
    {
        return $booking->status === BookingStatus::Confirmed
            && $booking->checked_in_at !== null
            && $booking->checked_out_at === null
            && ! $booking->isCoworking();
    }

    /**
     * The booking's own door code while it can still open the door: activated
     * and not past its period, or still waiting on activation within its
     * deadline (the desk may check in without activating it).
     */
    private function grantFor(Booking $booking): ?AccessGrant
    {
        $now = now();

        return AccessGrant::query()
            ->with('lock')
            ->where('source_type', AccessSourceType::Booking)
            ->where('source_id', $booking->id)
            ->whereHas('lock', fn (Builder $lock) => $lock->where('type', 'lock'))
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', $now))
            ->where(fn (Builder $q) => $q
                ->where('status', AccessGrantStatus::Activated)
                ->orWhere(fn (Builder $issued) => $issued
                    ->where('status', AccessGrantStatus::Issued)
                    ->where('must_activate_by', '>=', $now)))
            ->latest('id')
            ->first();
    }

    private function log(AccessGrant $grant, AccessEventType $type, User $actor): void
    {
        AccessEvent::create([
            'device_id' => $grant->lock_id,
            'access_grant_id' => $grant->id,
            'event_type' => $type,
            'channel' => AccessEventChannel::AppRemote,
            'actor_user_id' => $actor->id,
            'occurred_at' => now(),
        ]);
    }
}
