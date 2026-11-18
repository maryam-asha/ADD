<?php

namespace Tests\Feature\Booking;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Enums\AccessRevokeReason;
use App\Domain\Access\Enums\AccessSourceType;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Enums\TerminationSource;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Device;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST member/bookings/{booking}/check-out: the member leaving a checked-in
 * room, office or hall before its end. It closes now at its full booked
 * price and its door code stops working; coworking is refused.
 */
class MemberBookingCheckOutTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, SettingSeeder::class]);
        Carbon::setTestNow(Carbon::parse('2026-08-17 11:00:00', 'Asia/Damascus'));
        Http::preventStrayRequests();
        config(['services.ttlock.base_url' => 'https://api.sciener.test']);
        Http::fake([
            'api.sciener.test/oauth2/token' => Http::response(['access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 7776000], 200),
            'api.sciener.test/v3/keyboardPwd/delete' => Http::response(['errcode' => 0, 'errmsg' => ''], 200),
        ]);
        $this->member = User::factory()->create();
        $this->member->assignRole('member');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function booking(array $attributes = [], SpaceType $type = SpaceType::Room): Booking
    {
        $space = Space::factory()->create(['space_type' => $type, 'hourly_rate_points' => 100]);

        return Booking::factory()->create([
            'space_id' => $space->id,
            'user_id' => $this->member->id,
            'status' => BookingStatus::Confirmed,
            'start_at' => now()->subHour(),
            'end_at' => now()->addHour(),
            'checked_in_at' => now()->subMinutes(50),
            'price_points' => 200,
            'payment_state' => PaymentState::Unpaid,
            ...$attributes,
        ]);
    }

    private function checkOut(Booking $booking, ?User $as = null): TestResponse
    {
        Sanctum::actingAs($as ?? $this->member, ['member-app']);

        return $this->withHeader('lang', 'en')->postJson("/api/v1/member/bookings/{$booking->id}/check-out");
    }

    public function test_a_member_checks_out_of_a_room_now_at_its_full_price(): void
    {
        $booking = $this->booking();

        $this->checkOut($booking)
            ->assertOk()
            ->assertExactJson(['message' => __('api.booking.checked_out', [], 'en')]);

        $booking->refresh();
        $this->assertTrue($booking->checked_out_at->equalTo(now()));
        $this->assertSame(TerminationSource::Member, $booking->termination_source);
        $this->assertSame(BookingStatus::Completed, $booking->status);
        $this->assertSame(200, $booking->price_points);
    }

    public function test_the_room_door_code_is_revoked(): void
    {
        $booking = $this->booking();
        $lock = Device::factory()->create(['type' => 'lock', 'space_id' => $booking->space_id]);
        $grant = AccessGrant::factory()->create([
            'lock_id' => $lock->id,
            'grantee_type' => OwnerType::User,
            'grantee_id' => $this->member->id,
            'source_type' => AccessSourceType::Booking,
            'source_id' => $booking->id,
            'status' => AccessGrantStatus::Activated,
            'activated_at' => now(),
            'expires_at' => $booking->end_at,
        ]);

        $this->checkOut($booking)->assertOk();

        $grant->refresh();
        $this->assertSame(AccessGrantStatus::Revoked, $grant->status);
        $this->assertSame(AccessRevokeReason::CheckedOut, $grant->revoke_reason);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v3/keyboardPwd/delete'));
    }

    public function test_the_home_card_offers_check_out_only_while_active(): void
    {
        $booking = $this->booking();
        Sanctum::actingAs($this->member, ['member-app']);

        $this->getJson('/api/v1/member/home')
            ->assertOk()
            ->assertJsonPath('data.active_booking.0.id', $booking->id)
            ->assertJsonPath('data.active_booking.0.check_out.available', true);
    }

    public function test_a_booking_not_yet_checked_in_or_already_closed_is_refused(): void
    {
        $this->checkOut($this->booking(['checked_in_at' => null]))
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.reception.not_checked_in', [], 'en'));

        $this->checkOut($this->booking(['checked_out_at' => now()->subMinute()]))
            ->assertStatus(409);
    }

    public function test_a_coworking_booking_is_refused(): void
    {
        $booking = $this->booking(type: SpaceType::CoSpace);

        $this->checkOut($booking)
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.booking.check_out_coworking', [], 'en'));

        $this->assertNull($booking->fresh()->checked_out_at);
    }

    public function test_a_member_cannot_check_out_of_someone_elses_booking(): void
    {
        $other = User::factory()->create();
        $other->assignRole('member');
        $booking = $this->booking();

        $this->checkOut($booking, $other)->assertForbidden();

        $this->assertNull($booking->fresh()->checked_out_at);
    }
}
