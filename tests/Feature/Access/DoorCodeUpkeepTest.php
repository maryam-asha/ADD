<?php

namespace Tests\Feature\Access;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Enums\AccessSourceType;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Access\Services\PasscodeIssuanceService;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Models\Device;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Keeping a booking's door code right after it is first issued: issuing only
 * where there is a usable lock, re-issuing after maintenance, following an
 * extension, and the desk's manual re-issue.
 */
class DoorCodeUpkeepTest extends TestCase
{
    use RefreshDatabase;

    private int $nextVendorId = 500;

    private bool $issueFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'));
        Http::preventStrayRequests();
        Http::fake([
            'api.sciener.test/oauth2/token' => Http::response(['access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 7776000], 200),
            'api.sciener.test/v3/keyboardPwd/get' => fn () => $this->issueFails
                ? Http::response(['errcode' => -2012, 'errmsg' => 'no gateway'], 200)
                : Http::response(['keyboardPwd' => '24680'.$this->nextVendorId, 'keyboardPwdId' => $this->nextVendorId++], 200),
            'api.sciener.test/v3/keyboardPwd/delete' => Http::response(['errcode' => 0, 'errmsg' => ''], 200),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function roomWithLock(?Space $space = null): Space
    {
        $space ??= Space::factory()->room()->create();
        Device::factory()->create(['space_id' => $space->id, 'type' => 'lock', 'external_ref' => '33804038']);

        return $space;
    }

    private function booking(Space $space, array $overrides = []): Booking
    {
        return Booking::factory()->create(array_merge([
            'space_id' => $space->id,
            'user_id' => User::factory()->create()->id,
            'status' => BookingStatus::Confirmed,
            'start_at' => now()->addMinutes(30),
            'end_at' => now()->addHours(2),
        ], $overrides));
    }

    private function grantFor(Booking $booking, AccessGrantStatus $status, array $overrides = []): AccessGrant
    {
        return AccessGrant::factory()->create(array_merge([
            'lock_id' => $booking->space->devices()->where('type', 'lock')->value('id'),
            'grantee_type' => OwnerType::User,
            'grantee_id' => $booking->user_id,
            'source_type' => AccessSourceType::Booking,
            'source_id' => $booking->id,
            'status' => $status,
            'activated_at' => $status === AccessGrantStatus::Activated ? now() : null,
            'expires_at' => $booking->end_at,
            'vendor_keyboard_pwd_id' => 42,
        ], $overrides));
    }

    private function issue(): void
    {
        $this->artisan('access:issue-grants-on-cancellation-window-close')->assertSuccessful();
    }

    // --- issuing only where there is a usable lock ---------------------------

    public function test_a_booking_on_a_space_without_a_lock_is_skipped_without_an_error(): void
    {
        Log::spy();
        $booking = $this->booking(Space::factory()->room()->create());

        $this->issue();

        $this->assertSame(0, $booking->accessGrants()->count());
        Log::shouldNotHaveReceived('error');
        Http::assertNothingSent();
    }

    public function test_a_booking_on_a_space_in_maintenance_is_not_issued_a_code(): void
    {
        $booking = $this->booking($this->roomWithLock(Space::factory()->room()->maintenance()->create()));

        $this->issue();

        $this->assertSame(0, $booking->accessGrants()->count());
    }

    public function test_a_booking_whose_code_maintenance_revoked_gets_a_new_one_once_the_space_is_active(): void
    {
        $booking = $this->booking($this->roomWithLock());
        $this->grantFor($booking, AccessGrantStatus::Revoked);

        $this->issue();

        $this->assertSame(1, $booking->accessGrants()->where('status', AccessGrantStatus::Issued)->count());
    }

    public function test_a_booking_whose_code_expired_unused_is_left_to_the_desk(): void
    {
        $booking = $this->booking($this->roomWithLock());
        $this->grantFor($booking, AccessGrantStatus::Expired);

        $this->issue();

        $this->assertSame(1, $booking->accessGrants()->count());
        Http::assertNothingSent();
    }

    // --- following an extension ----------------------------------------------

    public function test_an_extended_booking_gets_a_code_to_its_new_end_and_the_old_code_is_deleted(): void
    {
        $booking = $this->booking($this->roomWithLock(), ['start_at' => now()->subMinutes(30)]);
        $grant = $this->grantFor($booking, AccessGrantStatus::Activated);
        $booking->update(['end_at' => $booking->end_at->copy()->addHour()]);

        app(PasscodeIssuanceService::class)->extendForBooking($booking->fresh());

        $grant->refresh();
        $this->assertSame(AccessGrantStatus::Activated, $grant->status);
        $this->assertTrue($grant->expires_at->equalTo($booking->fresh()->end_at));
        $this->assertSame(500, $grant->vendor_keyboard_pwd_id);
        $this->assertSame('24680500', $grant->passcode_value);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v3/keyboardPwd/get')
            && (int) $r['startDate'] === now()->getTimestampMs()
            && (int) $r['endDate'] === $booking->fresh()->end_at->getTimestampMs());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v3/keyboardPwd/delete') && (int) $r['keyboardPwdId'] === 42);
    }

    public function test_an_extension_the_lock_refuses_still_moves_the_grant_and_keeps_the_old_code(): void
    {
        $this->issueFails = true;
        $booking = $this->booking($this->roomWithLock());
        $grant = $this->grantFor($booking, AccessGrantStatus::Issued);
        $booking->update(['end_at' => $booking->end_at->copy()->addHour()]);

        app(PasscodeIssuanceService::class)->extendForBooking($booking->fresh());

        $grant->refresh();
        $this->assertTrue($grant->expires_at->equalTo($booking->fresh()->end_at));
        $this->assertSame(42, $grant->vendor_keyboard_pwd_id);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/v3/keyboardPwd/delete'));
    }

    public function test_an_extension_leaves_a_revoked_code_alone(): void
    {
        $booking = $this->booking($this->roomWithLock());
        $grant = $this->grantFor($booking, AccessGrantStatus::Revoked);
        $booking->update(['end_at' => $booking->end_at->copy()->addHour()]);

        app(PasscodeIssuanceService::class)->extendForBooking($booking->fresh());

        $this->assertTrue($grant->fresh()->expires_at->lessThan($booking->fresh()->end_at));
        Http::assertNothingSent();
    }

    // --- the desk's re-issue --------------------------------------------------

    private function actingAsDesk(): void
    {
        $desk = User::factory()->create();
        $desk->assignRole('operations');
        Sanctum::actingAs($desk, ['*']);
    }

    public function test_the_desk_reissues_a_code_and_the_old_live_one_is_revoked(): void
    {
        $this->actingAsDesk();
        $booking = $this->booking($this->roomWithLock());
        $old = $this->grantFor($booking, AccessGrantStatus::Issued);

        $response = $this->postJson("/api/v1/admin/reception/bookings/{$booking->id}/access-grant/reissue");

        $response->assertCreated()->assertJsonPath('data.status', 'issued');
        $this->assertNotSame($old->id, $response->json('data.id'));
        $this->assertSame(AccessGrantStatus::Revoked, $old->fresh()->status);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v3/keyboardPwd/delete') && (int) $r['keyboardPwdId'] === 42);
    }

    public function test_the_desk_reissues_a_code_that_expired_unused(): void
    {
        $this->actingAsDesk();
        $booking = $this->booking($this->roomWithLock());
        $this->grantFor($booking, AccessGrantStatus::Expired);

        $this->postJson("/api/v1/admin/reception/bookings/{$booking->id}/access-grant/reissue")->assertCreated();

        $this->assertSame(1, $booking->accessGrants()->where('status', AccessGrantStatus::Issued)->count());
    }

    public function test_a_lock_that_refuses_the_new_code_leaves_the_old_one_live(): void
    {
        $this->actingAsDesk();
        $this->issueFails = true;
        $booking = $this->booking($this->roomWithLock());
        $old = $this->grantFor($booking, AccessGrantStatus::Activated);

        $this->postJson("/api/v1/admin/reception/bookings/{$booking->id}/access-grant/reissue")->assertStatus(503);

        $this->assertSame(AccessGrantStatus::Activated, $old->fresh()->status);
        $this->assertSame(1, $booking->accessGrants()->count());
    }

    public function test_a_cancelled_or_ended_booking_or_a_room_without_a_lock_gets_no_new_code(): void
    {
        $this->actingAsDesk();
        $cancelled = $this->booking($this->roomWithLock(), ['status' => BookingStatus::Cancelled]);
        $ended = $this->booking($this->roomWithLock(), ['start_at' => now()->subHours(3), 'end_at' => now()->subHour()]);
        $noLock = $this->booking(Space::factory()->room()->create());

        $this->postJson("/api/v1/admin/reception/bookings/{$cancelled->id}/access-grant/reissue")->assertStatus(422);
        $this->postJson("/api/v1/admin/reception/bookings/{$ended->id}/access-grant/reissue")->assertStatus(422);
        $this->postJson("/api/v1/admin/reception/bookings/{$noLock->id}/access-grant/reissue")->assertStatus(422);
        Http::assertNothingSent();
    }
}
