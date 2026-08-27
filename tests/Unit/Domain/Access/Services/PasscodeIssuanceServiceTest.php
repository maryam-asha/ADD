<?php

namespace Tests\Unit\Domain\Access\Services;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Access\Services\PasscodeIssuanceService;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\AllocationModel;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Models\Device;
use App\Domain\Foundation\Models\Space;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PasscodeIssuanceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.ttlock.base_url' => 'https://api.sciener.test']);
        Http::fake(['api.sciener.test/oauth2/token' => Http::response([
            'access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 7776000,
        ], 200)]);
    }

    public function test_issue_for_booking_creates_an_issued_grant_with_24h_activation_window(): void
    {
        Http::fake(['api.sciener.test/v3/keyboardPwd/get' => Http::response(['keyboardPwd' => '112233', 'keyboardPwdId' => 5], 200)]);
        // Space::factory() leaves allocation_model null (nullable on the
        // spaces table itself — a Phase 1 decision, see SpaceFactory), but
        // access_grants.allocation_model is NOT NULL, so this test's space
        // needs one set explicitly, same as any other booking-relevant test.
        $space = Space::factory()->create(['allocation_model' => AllocationModel::BookingHourly]);
        Device::factory()->create(['space_id' => $space->id, 'type' => 'lock', 'external_ref' => '77']);
        $booking = Booking::factory()->create(['space_id' => $space->id]);

        $grant = app(PasscodeIssuanceService::class)->issueForBooking($booking);

        $this->assertSame(AccessGrantStatus::Issued, $grant->status);
        $this->assertSame('112233', $grant->passcode_value);
        $this->assertSame(5, $grant->vendor_keyboard_pwd_id);
        $this->assertEqualsWithDelta($grant->issued_at->addHours(24)->timestamp, $grant->must_activate_by->timestamp, 2);
    }

    /**
     * Final-review I3 — the vendor-facing passcode window must start at the
     * booking's own start time, not at issuance, or the keypad code would
     * be live (and overlap whoever holds the space earlier) hours before
     * the booked slot begins.
     */
    public function test_issue_for_booking_sends_the_bookings_start_at_as_the_vendor_window_start(): void
    {
        Http::fake(['api.sciener.test/v3/keyboardPwd/get' => Http::response(['keyboardPwd' => '112233', 'keyboardPwdId' => 5], 200)]);
        $space = Space::factory()->create(['allocation_model' => AllocationModel::BookingHourly]);
        Device::factory()->create(['space_id' => $space->id, 'type' => 'lock', 'external_ref' => '77']);
        $booking = Booking::factory()->create([
            'space_id' => $space->id,
            'start_at' => now()->addHours(5),
            'end_at' => now()->addHours(6),
        ]);

        app(PasscodeIssuanceService::class)->issueForBooking($booking);

        $expectedStartDate = $booking->start_at->getTimestamp() * 1000;
        Http::assertSent(fn ($request) => $request->url() === 'https://api.sciener.test/v3/keyboardPwd/get'
            && (int) $request['startDate'] === $expectedStartDate);
    }

    public function test_expire_overdue_marks_only_issued_grants_past_must_activate_by(): void
    {
        Http::fake(['api.sciener.test/v3/keyboardPwd/delete' => Http::response(['errcode' => 0, 'errmsg' => ''], 200)]);
        $overdue = AccessGrant::factory()->create(['status' => AccessGrantStatus::Issued, 'must_activate_by' => now()->subHour()]);
        $notYet = AccessGrant::factory()->create(['status' => AccessGrantStatus::Issued, 'must_activate_by' => now()->addHour()]);
        $alreadyActivated = AccessGrant::factory()->activated()->create(['must_activate_by' => now()->subHour()]);

        $count = app(PasscodeIssuanceService::class)->expireOverdue();

        $this->assertSame(1, $count);
        $this->assertSame(AccessGrantStatus::Expired, $overdue->fresh()->status);
        $this->assertSame(AccessGrantStatus::Issued, $notYet->fresh()->status);
        $this->assertSame(AccessGrantStatus::Activated, $alreadyActivated->fresh()->status);
    }

    /**
     * TTLock's own 24h-unused auto-invalidation is measured from the
     * passcode's vendor-side Start Time, which is booking->start_at, not
     * issued_at (which must_activate_by is based on) — the two clocks can
     * diverge for a same-day booking issued well before a late start_at.
     * expireOverdue() must therefore delete the vendor passcode itself
     * rather than relying on the vendor to have already invalidated it.
     */
    public function test_expire_overdue_deletes_the_vendor_passcode_for_an_expired_grant(): void
    {
        Http::fake(['api.sciener.test/v3/keyboardPwd/delete' => Http::response(['errcode' => 0, 'errmsg' => ''], 200)]);
        $lock = Device::factory()->create(['type' => 'lock', 'external_ref' => '77']);
        $overdue = AccessGrant::factory()->create([
            'lock_id' => $lock->id,
            'status' => AccessGrantStatus::Issued,
            'must_activate_by' => now()->subHour(),
            'vendor_keyboard_pwd_id' => 42,
        ]);

        app(PasscodeIssuanceService::class)->expireOverdue();

        $this->assertSame(AccessGrantStatus::Expired, $overdue->fresh()->status);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.sciener.test/v3/keyboardPwd/delete'
            && $request['lockId'] === '77'
            && $request['keyboardPwdId'] === 42);
    }

    /**
     * Mirrors revoke()'s null-guard — a grant with no vendor_keyboard_pwd_id
     * (never actually reached the vendor, or already cleared) must not
     * attempt a vendor call, and expiring it must not throw.
     */
    public function test_expire_overdue_skips_the_vendor_delete_call_when_vendor_keyboard_pwd_id_is_null(): void
    {
        Log::spy();
        $lock = Device::factory()->create(['type' => 'lock', 'external_ref' => '77']);
        $overdue = AccessGrant::factory()->create([
            'lock_id' => $lock->id,
            'status' => AccessGrantStatus::Issued,
            'must_activate_by' => now()->subHour(),
            'vendor_keyboard_pwd_id' => null,
        ]);

        $count = app(PasscodeIssuanceService::class)->expireOverdue();

        $this->assertSame(1, $count);
        $this->assertSame(AccessGrantStatus::Expired, $overdue->fresh()->status);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'keyboardPwd/delete'));
        Log::shouldHaveReceived('warning')->once();
    }

    /**
     * A vendor-delete failure during expiry is logged, not a reason to
     * leave the grant Issued or roll back the status change already made —
     * a DB status of Expired must stick even when the vendor call fails.
     */
    public function test_expire_overdue_leaves_the_grant_expired_even_when_the_vendor_delete_call_fails(): void
    {
        Http::fake([
            'api.sciener.test/v3/keyboardPwd/delete' => fn () => throw new ConnectionException('Connection timed out'),
        ]);
        $lock = Device::factory()->create(['type' => 'lock', 'external_ref' => '77']);
        $overdue = AccessGrant::factory()->create([
            'lock_id' => $lock->id,
            'status' => AccessGrantStatus::Issued,
            'must_activate_by' => now()->subHour(),
            'vendor_keyboard_pwd_id' => 42,
        ]);

        $count = app(PasscodeIssuanceService::class)->expireOverdue();

        $this->assertSame(1, $count);
        $this->assertSame(AccessGrantStatus::Expired, $overdue->fresh()->status);
    }

    public function test_revoke_for_space_revokes_and_deletes_vendor_passcode_for_issued_and_activated_grants(): void
    {
        Http::fake(['api.sciener.test/v3/keyboardPwd/delete' => Http::response(['errcode' => 0, 'errmsg' => ''], 200)]);
        $space = Space::factory()->create(['status' => OperationalStatus::Maintenance]);
        $lock = Device::factory()->create(['space_id' => $space->id, 'type' => 'lock', 'external_ref' => '77']);
        $issued = AccessGrant::factory()->create(['lock_id' => $lock->id, 'status' => AccessGrantStatus::Issued]);
        $activated = AccessGrant::factory()->activated()->create(['lock_id' => $lock->id]);
        $alreadyRevoked = AccessGrant::factory()->revoked()->create(['lock_id' => $lock->id]);

        app(PasscodeIssuanceService::class)->revokeForSpace($space);

        $this->assertSame(AccessGrantStatus::Revoked, $issued->fresh()->status);
        $this->assertSame(AccessGrantStatus::Revoked, $activated->fresh()->status);
        Http::assertSentCount(3); // token + 2 deletes (not 3 — the already-revoked grant is untouched)
    }

    /**
     * Bundled minor — vendor_keyboard_pwd_id is a nullable column;
     * deletePasscode() was being called with it unconditionally, which
     * would throw a TypeError instead of the vendor call it was meant to
     * make. Revocation of our own status must still succeed.
     */
    public function test_revoke_for_space_skips_the_vendor_delete_call_when_vendor_keyboard_pwd_id_is_null(): void
    {
        Log::spy();
        $space = Space::factory()->create(['status' => OperationalStatus::Maintenance]);
        $lock = Device::factory()->create(['space_id' => $space->id, 'type' => 'lock', 'external_ref' => '77']);
        $grant = AccessGrant::factory()->create([
            'lock_id' => $lock->id, 'status' => AccessGrantStatus::Issued, 'vendor_keyboard_pwd_id' => null,
        ]);

        app(PasscodeIssuanceService::class)->revokeForSpace($space);

        $this->assertSame(AccessGrantStatus::Revoked, $grant->fresh()->status);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'keyboardPwd/delete'));
        Log::shouldHaveReceived('warning')->once();
    }

    /**
     * Final-review I2 (fault-isolation half) — a non-TTLockException
     * failure (e.g. the ConnectionException Fix 4 now converts) inside one
     * grant's vendor delete call must not abort the rest of the ->each()
     * batch.
     */
    public function test_revoke_for_space_isolates_one_grants_vendor_failure_from_the_rest_of_the_batch(): void
    {
        Http::fake([
            'api.sciener.test/v3/keyboardPwd/delete' => fn () => throw new ConnectionException('Connection timed out'),
        ]);
        $space = Space::factory()->create(['status' => OperationalStatus::Maintenance]);
        $lock = Device::factory()->create(['space_id' => $space->id, 'type' => 'lock', 'external_ref' => '77']);
        $first = AccessGrant::factory()->create(['lock_id' => $lock->id, 'status' => AccessGrantStatus::Issued]);
        $second = AccessGrant::factory()->create(['lock_id' => $lock->id, 'status' => AccessGrantStatus::Issued]);

        app(PasscodeIssuanceService::class)->revokeForSpace($space);

        // Both grants are revoked in our own DB regardless of the vendor
        // call's outcome — the widened catch means the first grant's
        // ConnectionException doesn't stop the second from being
        // processed.
        $this->assertSame(AccessGrantStatus::Revoked, $first->fresh()->status);
        $this->assertSame(AccessGrantStatus::Revoked, $second->fresh()->status);
    }

    /**
     * Final-review C1 — the new method PasscodeIssuanceService::revokeForBooking()
     * scopes revocation to one booking's own grants, not every grant on
     * its space's lock.
     */
    public function test_revoke_for_booking_only_revokes_that_bookings_own_grants(): void
    {
        Http::fake(['api.sciener.test/v3/keyboardPwd/delete' => Http::response(['errcode' => 0, 'errmsg' => ''], 200)]);
        $space = Space::factory()->create(['allocation_model' => AllocationModel::BookingHourly]);
        $lock = Device::factory()->create(['space_id' => $space->id, 'type' => 'lock', 'external_ref' => '77']);
        $booking = Booking::factory()->create(['space_id' => $space->id]);
        $otherBooking = Booking::factory()->create(['space_id' => $space->id]);
        $thisBookingsGrant = AccessGrant::factory()->create([
            'lock_id' => $lock->id, 'status' => AccessGrantStatus::Issued, 'source_id' => $booking->id,
        ]);
        $otherBookingsGrant = AccessGrant::factory()->create([
            'lock_id' => $lock->id, 'status' => AccessGrantStatus::Issued, 'source_id' => $otherBooking->id,
        ]);

        app(PasscodeIssuanceService::class)->revokeForBooking($booking);

        $this->assertSame(AccessGrantStatus::Revoked, $thisBookingsGrant->fresh()->status);
        $this->assertSame(AccessGrantStatus::Issued, $otherBookingsGrant->fresh()->status);
    }
}
