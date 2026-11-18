<?php

namespace Tests\Feature\Access;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Services\PasscodeIssuanceService;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingCancellationService;
use App\Domain\Foundation\Enums\AllocationModel;
use App\Domain\Foundation\Models\Device;
use App\Domain\Foundation\Models\Space;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Final-review C1 — BookingCancellationService::cancel() has no interaction
 * with the Access domain, so a same-day booking's already-issued grant
 * survived its own cancellation. This command closes that gap by polling
 * Booking's already-public status/accessGrants() from the Access domain's
 * side.
 */
class RevokeAccessGrantsOnBookingCancellationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'));
        Http::preventStrayRequests();
        config(['services.ttlock.base_url' => 'https://api.sciener.test']);
        Http::fake(['api.sciener.test/oauth2/token' => Http::response([
            'access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 7776000,
        ], 200)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_cancelled_same_day_bookings_grant_is_revoked(): void
    {
        Http::fake([
            'api.sciener.test/v3/keyboardPwd/get' => Http::response(['keyboardPwd' => '112233', 'keyboardPwdId' => 5], 200),
            'api.sciener.test/v3/keyboardPwd/delete' => Http::response(['errcode' => 0, 'errmsg' => ''], 200),
        ]);
        $space = Space::factory()->create(['allocation_model' => AllocationModel::BookingHourly]);
        Device::factory()->create(['space_id' => $space->id, 'type' => 'lock', 'external_ref' => '77']);
        // Same-day, and well before the cancellation window closes, so
        // BookingCancellationService::cancel() below is still allowed to
        // cancel it — exactly the scenario the doc's `$sameDay` branch
        // issues a grant for ahead of the window closing.
        $booking = Booking::factory()->create([
            'space_id' => $space->id,
            'start_at' => now()->addHours(2),
            'end_at' => now()->addHours(3),
        ]);

        $grant = app(PasscodeIssuanceService::class)->issueForBooking($booking);
        $this->assertSame(AccessGrantStatus::Issued, $grant->status);

        app(BookingCancellationService::class)->cancel($booking);

        $this->artisan('access:revoke-grants-on-booking-cancellation')->assertSuccessful();

        $this->assertSame(AccessGrantStatus::Revoked, $grant->fresh()->status);
    }

    public function test_a_confirmed_bookings_grant_is_left_alone(): void
    {
        Http::fake(['api.sciener.test/v3/keyboardPwd/get' => Http::response(['keyboardPwd' => '112233', 'keyboardPwdId' => 5], 200)]);
        $space = Space::factory()->create(['allocation_model' => AllocationModel::BookingHourly]);
        Device::factory()->create(['space_id' => $space->id, 'type' => 'lock', 'external_ref' => '77']);
        $booking = Booking::factory()->create([
            'space_id' => $space->id,
            'start_at' => now()->addHours(2),
            'end_at' => now()->addHours(3),
        ]);
        $grant = app(PasscodeIssuanceService::class)->issueForBooking($booking);

        $this->artisan('access:revoke-grants-on-booking-cancellation')->assertSuccessful();

        $this->assertSame(AccessGrantStatus::Issued, $grant->fresh()->status);
    }
}
