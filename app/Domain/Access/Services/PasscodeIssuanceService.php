<?php

namespace App\Domain\Access\Services;

use App\Domain\Access\Enums\AccessEventChannel;
use App\Domain\Access\Enums\AccessEventType;
use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Enums\AccessRevokeReason;
use App\Domain\Access\Enums\AccessSourceType;
use App\Domain\Access\Enums\PasscodeType;
use App\Domain\Access\Exceptions\LockAccessDeniedException;
use App\Domain\Access\Models\AccessEvent;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\SessionStartSource;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\AccessArrivalCheckIn;
use App\Domain\Foundation\Enums\AllocationModel;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Models\Device;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Membership\Enums\OwnerType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Owns the access_grants lifecycle end to end: issue (booking or lease),
 * activate (kiosk-confirmed arrival), revoke (maintenance conflict),
 * expire (never activated in time). docs/decisions/qr-lock-unlock.md §2.
 */
class PasscodeIssuanceService
{
    public function __construct(
        private readonly TTLockClient $ttlock,
        private readonly AccessArrivalCheckIn $arrivals,
    ) {}

    public function issueForBooking(Booking $booking): AccessGrant
    {
        $lock = $this->lockFor($booking->space);
        $issuedAt = now();

        // The vendor-facing passcode window starts at the booking's own
        // start time, not at issuance — issuance can happen hours (or, for
        // a same-day booking, up to ~24h) before the booked slot begins,
        // and the code must not be live on the keypad before then, or it
        // would overlap whoever holds the space earlier. issued_at/
        // must_activate_by (still now()-based) are unaffected.
        $vendor = $this->ttlock->addPeriodPasscode($lock, $booking->start_at, $booking->end_at);

        return AccessGrant::create([
            'lock_id' => $lock->id,
            'grantee_type' => OwnerType::User,
            'grantee_id' => $booking->user_id,
            'source_type' => AccessSourceType::Booking,
            'source_id' => $booking->id,
            // Optional on a space, required on a grant: a space left without
            // one is still booked, so its code is a booking's.
            'allocation_model' => $booking->space->allocation_model ?? AllocationModel::BookingHourly,
            'passcode_type' => PasscodeType::Period,
            'passcode_value' => $vendor['passcode'],
            'vendor_keyboard_pwd_id' => $vendor['vendor_passcode_id'],
            'issued_at' => $issuedAt,
            'must_activate_by' => $issuedAt->copy()->addHours(24),
            'expires_at' => $booking->end_at,
            'status' => AccessGrantStatus::Issued,
        ]);
    }

    /**
     * The desk giving a booking a new door code: one that expired unused,
     * was stopped, or that the member lost. The new code is put on the lock
     * first and only then are the booking's live ones revoked, so a vendor
     * failure leaves the door as it was. Under the booking's row lock, so two
     * clicks make one code.
     *
     * @throws LockAccessDeniedException
     */
    public function reissueForBooking(Booking $booking): AccessGrant
    {
        return DB::transaction(function () use ($booking) {
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();
            $locked->load('space');

            if ($locked->status !== BookingStatus::Confirmed || $locked->end_at->lte(now())) {
                throw new LockAccessDeniedException('api.access.reissue_not_allowed', 422);
            }

            if (! $this->hasLock($locked->space)) {
                throw new LockAccessDeniedException('api.access.space_has_no_lock', 422);
            }

            if ($locked->space->status !== OperationalStatus::Active) {
                throw new LockAccessDeniedException('api.access.space_not_active', 422);
            }

            try {
                $grant = $this->issueForBooking($locked);
            } catch (\Throwable $e) {
                Log::error('Failed to re-issue an access grant for a booking', [
                    'booking_id' => $locked->id, 'error' => $e->getMessage(),
                ]);

                throw new LockAccessDeniedException('api.access.issue_failed', 503);
            }

            $lock = $grant->lock;
            $locked->accessGrants()
                ->whereKeyNot($grant->id)
                ->whereIn('status', [AccessGrantStatus::Issued, AccessGrantStatus::Activated])
                ->get()
                ->each(fn (AccessGrant $old) => $this->revoke($old, $lock, AccessRevokeReason::Replaced));

            return $grant;
        });
    }

    /**
     * The office lock for a lease's company: activated at once (there is no
     * kiosk arrival for a tenancy, so expireOverdue() — Issued grants only —
     * never touches it) and live through the lease's last access day, on
     * the keypad and in our own expires_at alike.
     */
    public function issueForLease(OfficeLease $lease): AccessGrant
    {
        $lock = $this->lockFor($lease->space);
        $issuedAt = now();
        $endsAt = $lease->accessEndsOn()->endOfDay();

        $vendor = $this->ttlock->addPeriodPasscode($lock, $issuedAt, $endsAt);

        return AccessGrant::create([
            'lock_id' => $lock->id,
            'grantee_type' => OwnerType::Company,
            'grantee_id' => $lease->company_id,
            'source_type' => AccessSourceType::Tenancy,
            'source_id' => null,
            'office_lease_id' => $lease->id,
            'allocation_model' => AllocationModel::Tenancy,
            'passcode_type' => PasscodeType::Period,
            'passcode_value' => $vendor['passcode'],
            'vendor_keyboard_pwd_id' => $vendor['vendor_passcode_id'],
            'issued_at' => $issuedAt,
            'must_activate_by' => $issuedAt,
            'activated_at' => $issuedAt,
            'expires_at' => $endsAt,
            'status' => AccessGrantStatus::Activated,
        ]);
    }

    /** Ends every live grant of the lease, on the lock too. */
    public function revokeForLease(OfficeLease $lease): void
    {
        AccessGrant::query()
            ->where('office_lease_id', $lease->id)
            ->whereIn('status', [AccessGrantStatus::Issued, AccessGrantStatus::Activated])
            ->with('lock')
            ->get()
            ->each(fn (AccessGrant $grant) => $this->revoke($grant, $grant->lock, AccessRevokeReason::Lease));
    }

    public function hasLock(Space $space): bool
    {
        return $space->devices()->where('type', 'lock')->exists();
    }

    public function activate(AccessGrant $grant, User $actor): void
    {
        $activated = DB::transaction(function () use ($grant) {
            $locked = AccessGrant::query()->whereKey($grant->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== AccessGrantStatus::Issued) {
                throw new LockAccessDeniedException('api.access.grant_not_activatable', 409);
            }

            $locked->forceFill(['status' => AccessGrantStatus::Activated, 'activated_at' => now()])->save();

            return $locked;
        });

        AccessEvent::create([
            'device_id' => $activated->lock_id,
            'access_grant_id' => $activated->id,
            'event_type' => AccessEventType::Unlock,
            'channel' => AccessEventChannel::ReceptionActivation,
            'actor_user_id' => $actor->id,
            'occurred_at' => now(),
        ]);

        // Activation happens on the member's arrival: for a booking, its check-in.
        $this->arrivals->forGrant($activated, SessionStartSource::Reception);
    }

    /**
     * @return Collection<int, AccessGrant> the grants this call revoked
     */
    public function revokeForSpace(Space $space): Collection
    {
        $lock = $this->lockFor($space);

        return AccessGrant::query()
            ->where('lock_id', $lock->id)
            ->whereIn('status', [AccessGrantStatus::Issued, AccessGrantStatus::Activated])
            ->get()
            ->map(fn (AccessGrant $grant) => $this->revoke($grant, $lock, AccessRevokeReason::Maintenance))
            ->filter()
            ->values();
    }

    /**
     * Staff stopping one booking's code from the dashboard — the code comes
     * off the lock and is never re-issued on its own (the desk can re-issue
     * it). An office's code follows its lease instead: the hourly lease run
     * would only open the door again, so ending it is ending the lease.
     *
     * @throws LockAccessDeniedException
     */
    public function revokeByStaff(AccessGrant $grant, User $staff): AccessGrant
    {
        if ($grant->source_type === AccessSourceType::Tenancy || $grant->office_lease_id !== null) {
            throw new LockAccessDeniedException('api.access.tenancy_grant_follows_lease', 422);
        }

        $revoked = $this->revoke($grant, $grant->lock, AccessRevokeReason::Staff, $staff);

        if ($revoked === null) {
            throw new LockAccessDeniedException('api.access.grant_not_live', 409);
        }

        return $revoked;
    }

    /**
     * Belt-and-suspenders half of the fix for a cancelled booking's grant
     * never being revoked (docs/decisions/qr-lock-unlock.md's issuance
     * lifecycle has no cancellation trigger) — called by
     * RevokeAccessGrantsOnBookingCancellation. Mirrors revokeForSpace()'s
     * shape exactly, scoped to one booking's own grants instead of every
     * grant on a space's lock.
     */
    public function revokeForBooking(Booking $booking, AccessRevokeReason $reason = AccessRevokeReason::Cancellation): void
    {
        $grants = $booking->accessGrants()
            ->whereIn('status', [AccessGrantStatus::Issued, AccessGrantStatus::Activated])
            ->get();

        // No live code (a room without a lock, or one already gone): nothing to do.
        if ($grants->isEmpty()) {
            return;
        }

        $lock = $this->lockFor($booking->space);

        $grants->each(fn (AccessGrant $grant) => $this->revoke($grant, $lock, $reason));
    }

    /**
     * A booking that grew keeps its door to the new end: every live grant of
     * it runs to end_at in our records — what the QR and the door buttons
     * check — and gets a keypad code lasting as long, the old one deleted
     * once the new one is on the lock. A vendor failure keeps the old code
     * (logged) and still moves expires_at, so the app keeps opening the door.
     * Never throws: the extension is already saved.
     */
    public function extendForBooking(Booking $booking): void
    {
        $booking->accessGrants()
            ->whereIn('status', [AccessGrantStatus::Issued, AccessGrantStatus::Activated])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '<', $booking->end_at))
            ->with('lock')
            ->get()
            ->each(function (AccessGrant $grant) use ($booking) {
                try {
                    $this->extendGrant($grant, $booking);
                } catch (\Throwable $e) {
                    Log::error('Failed to extend the access grant of an extended booking', [
                        'access_grant_id' => $grant->id,
                        'booking_id' => $booking->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
    }

    private function extendGrant(AccessGrant $grant, Booking $booking): void
    {
        $vendor = null;

        try {
            // From now when the booking is under way: the lock wants a
            // period code used within 24h of its start.
            $vendor = $this->ttlock->addPeriodPasscode($grant->lock, $booking->start_at->copy()->max(now()), $booking->end_at);
        } catch (\Throwable $e) {
            Log::error('Failed to issue a longer TTLock passcode for an extended booking; the old code stays', [
                'access_grant_id' => $grant->id,
                'error' => $e->getMessage(),
            ]);
        }

        $replaced = DB::transaction(function () use ($grant, $booking, $vendor) {
            $locked = AccessGrant::query()->whereKey($grant->getKey())->lockForUpdate()->firstOrFail();

            // Revoked or expired in the meantime: the door stays closed.
            if (! in_array($locked->status, [AccessGrantStatus::Issued, AccessGrantStatus::Activated], true)) {
                return null;
            }

            $oldVendorId = $locked->vendor_keyboard_pwd_id;
            $locked->expires_at = $booking->end_at;

            if ($vendor !== null) {
                $locked->passcode_value = $vendor['passcode'];
                $locked->vendor_keyboard_pwd_id = $vendor['vendor_passcode_id'];
            }

            $locked->save();

            return ['old' => $oldVendorId];
        });

        if ($vendor === null) {
            return;
        }

        // The old code goes once the new one is recorded; a grant that ended
        // meanwhile takes the new one with it instead.
        $this->deleteVendorCode(
            $grant->id,
            $replaced === null ? $vendor['vendor_passcode_id'] : $replaced['old'],
            $grant->lock,
        );
    }

    public function expireOverdue(): int
    {
        $count = 0;

        AccessGrant::query()
            ->where('status', AccessGrantStatus::Issued)
            ->where('must_activate_by', '<', now())
            ->with('lock')
            ->chunkById(100, function ($grants) use (&$count) {
                foreach ($grants as $grant) {
                    // TTLock's own 24h-unused auto-invalidation is measured
                    // from the passcode's vendor-side Start Time, which is
                    // booking->start_at (see issueForBooking()) — not
                    // issued_at, which is what must_activate_by is still
                    // based on. For a same-day booking issued well before a
                    // late start_at, those two clocks no longer coincide,
                    // so we can't rely on the vendor to have already
                    // invalidated the code by the time we mark the grant
                    // Expired here. Delete it explicitly instead: a DB
                    // status of Expired must mean the door credential is
                    // actually dead.
                    $grant->update(['status' => AccessGrantStatus::Expired]);
                    $this->deleteVendorPasscode($grant, $grant->lock);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * @return AccessGrant|null the grant, revoked by this call; null when it
     *                          was no longer live
     */
    private function revoke(AccessGrant $grant, Device $lock, AccessRevokeReason $reason, ?User $by = null): ?AccessGrant
    {
        $revoked = DB::transaction(function () use ($grant, $reason, $by) {
            $locked = AccessGrant::query()->whereKey($grant->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [AccessGrantStatus::Issued, AccessGrantStatus::Activated], true)) {
                return null;
            }

            $locked->forceFill([
                'status' => AccessGrantStatus::Revoked,
                'revoke_reason' => $reason,
                'revoked_by' => $by?->id,
                'revoked_at' => now(),
            ])->save();

            return $locked;
        });

        if ($revoked === null) {
            return null;
        }

        $this->deleteVendorPasscode($revoked, $lock);

        return $revoked;
    }

    private function deleteVendorPasscode(AccessGrant $grant, Device $lock): void
    {
        $this->deleteVendorCode($grant->id, $grant->vendor_keyboard_pwd_id, $lock);
    }

    private function deleteVendorCode(int $grantId, ?int $vendorPasscodeId, Device $lock): void
    {
        if ($vendorPasscodeId === null) {
            Log::warning('Skipping TTLock passcode deletion for an access grant with no vendor_keyboard_pwd_id', [
                'access_grant_id' => $grantId,
            ]);

            return;
        }

        try {
            $this->ttlock->deletePasscode($lock, $vendorPasscodeId);
        } catch (\Throwable $e) {
            // Widened from `catch (TTLockException $e)`: any vendor-call
            // failure — including one Fix 4 didn't anticipate — must not
            // escape and abort the rest of the calling ->each()/chunkById()
            // batch (revokeForSpace()/revokeForBooking()/expireOverdue()
            // process every other grant in the same call regardless of
            // this one's outcome).
            Log::error('Failed to delete TTLock passcode for an access grant', [
                'access_grant_id' => $grantId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function lockFor(Space $space): Device
    {
        return $space->devices()->where('type', 'lock')->firstOrFail();
    }
}
