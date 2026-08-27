<?php

namespace App\Domain\Access\Services;

use App\Domain\Access\Enums\AccessEventChannel;
use App\Domain\Access\Enums\AccessEventType;
use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Enums\AccessSourceType;
use App\Domain\Access\Enums\PasscodeType;
use App\Domain\Access\Exceptions\LockAccessDeniedException;
use App\Domain\Access\Models\AccessEvent;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\AllocationModel;
use App\Domain\Foundation\Models\Device;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Owns the access_grants lifecycle end to end: issue (booking or tenancy),
 * activate (kiosk-confirmed arrival), revoke (maintenance conflict),
 * expire (never activated in time). docs/decisions/qr-lock-unlock.md §2.
 */
class PasscodeIssuanceService
{
    public function __construct(private readonly TTLockClient $ttlock) {}

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
            'allocation_model' => $booking->space->allocation_model,
            'passcode_type' => PasscodeType::Period,
            'passcode_value' => $vendor['passcode'],
            'vendor_keyboard_pwd_id' => $vendor['vendor_passcode_id'],
            'issued_at' => $issuedAt,
            'must_activate_by' => $issuedAt->copy()->addHours(24),
            'expires_at' => $booking->end_at,
            'status' => AccessGrantStatus::Issued,
        ]);
    }

    public function issueForTenancy(Company $company, Device $lock): AccessGrant
    {
        $issuedAt = now();
        // TTLock's Period type requires a bounded endDate; a tenancy grant
        // is conceptually open-ended, so the vendor-side window is set far
        // enough out to be operationally permanent. Our own status/
        // expires_at (left null below) are what actually gate access —
        // this bound only exists to satisfy the vendor API's shape.
        $vendorEnd = $issuedAt->copy()->addYears(5);

        $vendor = $this->ttlock->addPeriodPasscode($lock, $issuedAt, $vendorEnd);

        return AccessGrant::create([
            'lock_id' => $lock->id,
            'grantee_type' => OwnerType::Company,
            'grantee_id' => $company->id,
            'source_type' => AccessSourceType::Tenancy,
            'source_id' => null,
            'allocation_model' => AllocationModel::Tenancy,
            'passcode_type' => PasscodeType::Period,
            'passcode_value' => $vendor['passcode'],
            'vendor_keyboard_pwd_id' => $vendor['vendor_passcode_id'],
            'issued_at' => $issuedAt,
            'must_activate_by' => $issuedAt->copy()->addHours(24),
            'expires_at' => null,
            'status' => AccessGrantStatus::Issued,
        ]);
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
    }

    public function revokeForSpace(Space $space): void
    {
        $lock = $this->lockFor($space);

        AccessGrant::query()
            ->where('lock_id', $lock->id)
            ->whereIn('status', [AccessGrantStatus::Issued, AccessGrantStatus::Activated])
            ->get()
            ->each(fn (AccessGrant $grant) => $this->revoke($grant, $lock));
    }

    /**
     * Belt-and-suspenders half of the fix for a cancelled booking's grant
     * never being revoked (docs/decisions/qr-lock-unlock.md's issuance
     * lifecycle has no cancellation trigger) — called by
     * RevokeAccessGrantsOnBookingCancellation. Mirrors revokeForSpace()'s
     * shape exactly, scoped to one booking's own grants instead of every
     * grant on a space's lock.
     */
    public function revokeForBooking(Booking $booking): void
    {
        $lock = $this->lockFor($booking->space);

        $booking->accessGrants()
            ->whereIn('status', [AccessGrantStatus::Issued, AccessGrantStatus::Activated])
            ->get()
            ->each(fn (AccessGrant $grant) => $this->revoke($grant, $lock));
    }

    public function expireOverdue(): int
    {
        $count = 0;

        AccessGrant::query()
            ->where('status', AccessGrantStatus::Issued)
            ->where('must_activate_by', '<', now())
            ->chunkById(100, function ($grants) use (&$count) {
                foreach ($grants as $grant) {
                    // TTLock's own Period passcode already auto-invalidates
                    // on the lock after 24h unused — no vendor call needed
                    // here, only our own status kept in sync.
                    $grant->update(['status' => AccessGrantStatus::Expired]);
                    $count++;
                }
            });

        return $count;
    }

    private function revoke(AccessGrant $grant, Device $lock): void
    {
        $revoked = DB::transaction(function () use ($grant) {
            $locked = AccessGrant::query()->whereKey($grant->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [AccessGrantStatus::Issued, AccessGrantStatus::Activated], true)) {
                return null;
            }

            $locked->forceFill(['status' => AccessGrantStatus::Revoked])->save();

            return $locked;
        });

        if ($revoked === null) {
            return;
        }

        if ($revoked->vendor_keyboard_pwd_id === null) {
            Log::warning('Skipping TTLock passcode deletion for a revoked access grant with no vendor_keyboard_pwd_id', [
                'access_grant_id' => $revoked->id,
            ]);

            return;
        }

        try {
            $this->ttlock->deletePasscode($lock, $revoked->vendor_keyboard_pwd_id);
        } catch (\Throwable $e) {
            // Widened from `catch (TTLockException $e)`: any vendor-call
            // failure — including one Fix 4 didn't anticipate — must not
            // escape and abort the rest of the calling ->each() batch
            // (revokeForSpace()/revokeForBooking() revoke every other
            // grant in the same call regardless of this one's outcome).
            Log::error('Failed to delete TTLock passcode after revoking an access grant', [
                'access_grant_id' => $revoked->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function lockFor(Space $space): Device
    {
        return $space->devices()->where('type', 'lock')->firstOrFail();
    }
}
