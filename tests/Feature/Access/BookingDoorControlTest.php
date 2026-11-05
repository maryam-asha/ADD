<?php

namespace Tests\Feature\Access;

use App\Domain\Access\Enums\AccessEventChannel;
use App\Domain\Access\Enums\AccessEventType;
use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Enums\AccessSourceType;
use App\Domain\Access\Models\AccessEvent;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Models\Device;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The live room card's door buttons: once a room booking is checked in and
 * until it closes, its member opens and locks the booked room's door from
 * the app, no sticker scan.
 */
class BookingDoorControlTest extends TestCase
{
    use RefreshDatabase;

    private array $unlockReply = ['errcode' => 0, 'errmsg' => ''];

    private array $lockReply = ['errcode' => 0, 'errmsg' => ''];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'));
        Http::preventStrayRequests();
        config(['services.ttlock.base_url' => 'https://api.sciener.test']);
        Http::fake([
            'api.sciener.test/oauth2/token' => Http::response(['access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 7776000], 200),
            'api.sciener.test/v3/lock/unlock' => fn () => Http::response($this->unlockReply, 200),
            'api.sciener.test/v3/lock/lock' => fn () => Http::response($this->lockReply, 200),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: Booking, 2: AccessGrant} */
    private function checkedInRoom(AccessGrantStatus $status = AccessGrantStatus::Activated): array
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $booking = Booking::factory()->create([
            'space_id' => Space::factory()->room()->create()->id,
            'user_id' => $member->id,
            'status' => BookingStatus::Confirmed,
            'start_at' => now()->subMinutes(10),
            'end_at' => now()->addHours(2),
            'checked_in_at' => now()->subMinutes(5),
        ]);
        $grant = AccessGrant::factory()->create([
            'lock_id' => Device::factory()->create(['type' => 'lock', 'qr_value' => 'room-door'])->id,
            'grantee_type' => OwnerType::User,
            'grantee_id' => $member->id,
            'source_type' => AccessSourceType::Booking,
            'source_id' => $booking->id,
            'status' => $status,
            'activated_at' => $status === AccessGrantStatus::Activated ? now() : null,
            'must_activate_by' => now()->addHours(20),
            'expires_at' => $booking->end_at,
        ]);

        return [$member, $booking, $grant];
    }

    private function door(User $member, Booking $booking, string $action): TestResponse
    {
        Sanctum::actingAs($member, ['member-app']);

        return $this->withHeader('lang', 'en')->postJson("/api/v1/member/bookings/{$booking->id}/door/{$action}");
    }

    public function test_a_checked_in_member_opens_the_booked_room_without_scanning(): void
    {
        [$member, $booking, $grant] = $this->checkedInRoom();

        $this->door($member, $booking, 'unlock')
            ->assertOk()
            ->assertExactJson(['message' => __('api.access.unlocked', [], 'en')]);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v3/lock/unlock'));
        $this->assertDatabaseHas('access_events', [
            'access_grant_id' => $grant->id,
            'event_type' => AccessEventType::Unlock->value,
            'channel' => AccessEventChannel::AppRemote->value,
            'actor_user_id' => $member->id,
        ]);
    }

    public function test_a_checked_in_member_locks_the_booked_room(): void
    {
        [$member, $booking, $grant] = $this->checkedInRoom();

        $this->door($member, $booking, 'lock')
            ->assertOk()
            ->assertExactJson(['message' => __('api.access.locked', [], 'en')]);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v3/lock/lock'));
        $this->assertDatabaseHas('access_events', [
            'access_grant_id' => $grant->id,
            'event_type' => AccessEventType::Lock->value,
            'channel' => AccessEventChannel::AppRemote->value,
        ]);
    }

    public function test_the_buttons_do_nothing_before_check_in(): void
    {
        [$member, $booking] = $this->checkedInRoom();
        $booking->update(['checked_in_at' => null]);

        foreach (['unlock', 'lock'] as $action) {
            $this->door($member, $booking, $action)
                ->assertStatus(422)
                ->assertJsonPath('message', __('api.access.door_requires_check_in', [], 'en'));
        }

        Http::assertNothingSent();
    }

    public function test_the_buttons_stop_once_the_booking_closes(): void
    {
        [$member, $booking] = $this->checkedInRoom();
        $booking->update(['checked_out_at' => now()]);

        $this->door($member, $booking, 'unlock')->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_another_members_booking_is_refused(): void
    {
        [, $booking] = $this->checkedInRoom();
        $stranger = User::factory()->create();
        $stranger->assignRole('member');

        $this->door($stranger, $booking, 'unlock')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_a_revoked_code_leaves_the_door_unavailable(): void
    {
        [$member, $booking, $grant] = $this->checkedInRoom();
        $grant->update(['status' => AccessGrantStatus::Revoked]);

        $this->door($member, $booking, 'unlock')
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.access.door_unavailable', [], 'en'));
        Http::assertNothingSent();
    }

    public function test_an_unactivated_code_is_activated_by_the_first_open(): void
    {
        [$member, $booking, $grant] = $this->checkedInRoom(AccessGrantStatus::Issued);

        $this->door($member, $booking, 'unlock')->assertOk();

        $grant->refresh();
        $this->assertSame(AccessGrantStatus::Activated, $grant->status);
        $this->assertNotNull($grant->activated_at);
    }

    public function test_an_offline_gateway_fails_and_is_logged(): void
    {
        [$member, $booking, $grant] = $this->checkedInRoom();
        $this->unlockReply = ['errcode' => -2012, 'errmsg' => 'gateway offline'];

        $this->door($member, $booking, 'unlock')
            ->assertStatus(503)
            ->assertJsonPath('message', __('api.access.gateway_offline', [], 'en'));

        $this->assertSame(
            AccessEventType::FailedAttempt,
            AccessEvent::where('access_grant_id', $grant->id)->sole()->event_type,
        );
    }

    public function test_a_lock_without_remote_locking_says_so(): void
    {
        [$member, $booking] = $this->checkedInRoom();
        $this->lockReply = ['errcode' => -4043, 'errmsg' => 'not supported'];

        $this->door($member, $booking, 'lock')
            ->assertStatus(503)
            ->assertJsonPath('message', __('api.access.remote_lock_unsupported', [], 'en'));
    }

    public function test_the_home_card_offers_the_buttons_only_once_checked_in(): void
    {
        [$member, $booking] = $this->checkedInRoom();
        Sanctum::actingAs($member, ['member-app']);

        $this->getJson('/api/v1/member/home')
            ->assertOk()
            ->assertJsonPath('data.active_booking.state', 'active')
            ->assertJsonPath('data.active_booking.door.available', true);

        $booking->update(['checked_in_at' => null]);

        $this->getJson('/api/v1/member/home')
            ->assertJsonPath('data.active_booking.state', 'ready')
            ->assertJsonPath('data.active_booking.door.available', false);
    }
}
