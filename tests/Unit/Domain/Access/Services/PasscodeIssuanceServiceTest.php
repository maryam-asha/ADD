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
use Illuminate\Support\Facades\Http;
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

    public function test_expire_overdue_marks_only_issued_grants_past_must_activate_by(): void
    {
        $overdue = AccessGrant::factory()->create(['status' => AccessGrantStatus::Issued, 'must_activate_by' => now()->subHour()]);
        $notYet = AccessGrant::factory()->create(['status' => AccessGrantStatus::Issued, 'must_activate_by' => now()->addHour()]);
        $alreadyActivated = AccessGrant::factory()->activated()->create(['must_activate_by' => now()->subHour()]);

        $count = app(PasscodeIssuanceService::class)->expireOverdue();

        $this->assertSame(1, $count);
        $this->assertSame(AccessGrantStatus::Expired, $overdue->fresh()->status);
        $this->assertSame(AccessGrantStatus::Issued, $notYet->fresh()->status);
        $this->assertSame(AccessGrantStatus::Activated, $alreadyActivated->fresh()->status);
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
}
