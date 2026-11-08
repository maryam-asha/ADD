<?php

namespace Tests\Feature\Access;

use App\Domain\Access\Enums\AccessEventChannel;
use App\Domain\Access\Enums\AccessEventType;
use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Enums\AccessRevokeReason;
use App\Domain\Access\Enums\AccessSourceType;
use App\Domain\Access\Models\AccessEvent;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Models\Device;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What staff do to locks from the dashboard: open and lock a door, refresh
 * devices from the TTLock account, pick a lock from it, read the access log,
 * and list or stop door codes.
 */
class DashboardDoorControlTest extends TestCase
{
    use RefreshDatabase;

    private array $unlockReply = ['errcode' => 0, 'errmsg' => ''];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        Http::fake([
            'api.sciener.test/oauth2/token' => Http::response(['access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 7776000], 200),
            'api.sciener.test/v3/lock/unlock' => fn () => Http::response($this->unlockReply, 200),
            'api.sciener.test/v3/lock/lock' => Http::response(['errcode' => 0, 'errmsg' => ''], 200),
            'api.sciener.test/v3/keyboardPwd/delete' => Http::response(['errcode' => 0, 'errmsg' => ''], 200),
            'api.sciener.test/v3/keyboardPwd/get' => Http::response(['keyboardPwd' => '135790', 'keyboardPwdId' => 77], 200),
            'api.sciener.test/v3/gateway/list*' => Http::response(['list' => [
                ['gatewayId' => 2334668, 'gatewayName' => 'gateway', 'gatewayMac' => 'GW', 'isOnline' => 1, 'lockNum' => 1, 'networkName' => 'ADD'],
            ], 'pages' => 1], 200),
            'api.sciener.test/v3/lock/list*' => Http::response(['list' => [
                ['lockId' => 33804038, 'lockAlias' => 'Room A', 'lockMac' => 'MAC', 'electricQuantity' => 85, 'hasGateway' => 1, 'lockData' => 'secret'],
                ['lockId' => 4, 'lockAlias' => 'Spare', 'hasGateway' => 0],
            ], 'pages' => 1], 200),
        ]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        Sanctum::actingAs($this->admin, ['*']);
    }

    private function lock(array $attributes = []): Device
    {
        return Device::factory()->create(array_merge(['type' => 'lock', 'external_ref' => '33804038'], $attributes));
    }

    // --- opening and locking -------------------------------------------------

    public function test_staff_open_and_lock_a_door_and_each_is_logged_against_them(): void
    {
        $lock = $this->lock();

        $this->withHeader('lang', 'en')->postJson("/api/v1/admin/devices/{$lock->id}/unlock")->assertOk()->assertExactJson(['message' => 'Unlocked.']);
        $this->postJson("/api/v1/admin/devices/{$lock->id}/lock")->assertOk();

        $this->assertSame(2, AccessEvent::query()->where('channel', AccessEventChannel::Dashboard)->where('actor_user_id', $this->admin->id)->count());
        $this->assertDatabaseHas('access_events', ['device_id' => $lock->id, 'event_type' => AccessEventType::Lock->value]);
    }

    public function test_an_offline_gateway_is_a_503_and_a_logged_failed_attempt(): void
    {
        $this->unlockReply = ['errcode' => -2012, 'errmsg' => 'gateway offline'];
        $lock = $this->lock();

        $this->postJson("/api/v1/admin/devices/{$lock->id}/unlock")->assertStatus(503);

        $this->assertDatabaseHas('access_events', ['device_id' => $lock->id, 'event_type' => AccessEventType::FailedAttempt->value]);
    }

    public function test_a_device_that_is_not_a_linked_lock_cannot_be_opened(): void
    {
        $camera = Device::factory()->create(['type' => 'camera']);
        $unlinked = $this->lock(['external_ref' => null]);

        $this->postJson("/api/v1/admin/devices/{$camera->id}/unlock")->assertStatus(422);
        $this->postJson("/api/v1/admin/devices/{$unlinked->id}/unlock")->assertStatus(422);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/v3/lock/unlock'));
    }

    public function test_operations_needs_its_own_grant_to_open_a_door(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $this->postJson("/api/v1/admin/devices/{$this->lock()->id}/unlock")->assertForbidden();
    }

    // --- the TTLock account ----------------------------------------------------

    public function test_sync_refreshes_devices_from_the_account(): void
    {
        config(['services.ttlock.client_id' => 'client']);
        $lock = $this->lock();

        $this->postJson('/api/v1/admin/devices/sync')->assertOk();

        $this->assertSame(85, $lock->fresh()->metadata['battery']);
        $this->assertSame('online', $lock->fresh()->status);
    }

    public function test_sync_and_the_account_need_a_configured_account(): void
    {
        $this->postJson('/api/v1/admin/devices/sync')->assertStatus(422);
        $this->getJson('/api/v1/admin/devices/ttlock-account')->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_the_account_lists_locks_and_gateways_with_their_linked_rows_and_no_key_material(): void
    {
        config(['services.ttlock.client_id' => 'client']);
        $linked = $this->lock();

        $response = $this->getJson('/api/v1/admin/devices/ttlock-account')->assertOk();

        $response->assertJsonPath('data.locks.0.lock_id', '33804038')
            ->assertJsonPath('data.locks.0.linked_device_id', $linked->id)
            ->assertJsonPath('data.locks.1.linked_device_id', null)
            ->assertJsonPath('data.gateways.0.gateway_id', '2334668')
            ->assertJsonPath('data.gateways.0.is_online', true);
        $this->assertStringNotContainsString('lockData', $response->getContent());
        $this->assertStringNotContainsString('secret', $response->getContent());
    }

    // --- the access log and door codes -----------------------------------------

    private function bookingGrant(AccessGrantStatus $status = AccessGrantStatus::Activated): AccessGrant
    {
        $space = Space::factory()->room()->create();
        $lock = $this->lock(['space_id' => $space->id]);
        $member = User::factory()->create(['name' => 'Lina']);
        $booking = Booking::factory()->create([
            'space_id' => $space->id, 'user_id' => $member->id, 'status' => BookingStatus::Confirmed,
            'start_at' => now()->addMinutes(30), 'end_at' => now()->addHours(2),
        ]);

        return AccessGrant::factory()->create([
            'lock_id' => $lock->id, 'grantee_type' => OwnerType::User, 'grantee_id' => $member->id,
            'source_type' => AccessSourceType::Booking, 'source_id' => $booking->id, 'status' => $status,
            'vendor_keyboard_pwd_id' => 42, 'expires_at' => $booking->end_at,
        ]);
    }

    public function test_the_access_log_lists_events_newest_first_and_filters_by_lock(): void
    {
        $grant = $this->bookingGrant();
        $other = $this->lock(['external_ref' => '9']);
        AccessEvent::create(['device_id' => $grant->lock_id, 'access_grant_id' => $grant->id, 'event_type' => 'unlock', 'channel' => 'qr_scan', 'actor_user_id' => $grant->grantee_id, 'occurred_at' => now()->subMinute()]);
        AccessEvent::create(['device_id' => $other->id, 'event_type' => 'unlock', 'channel' => 'dashboard', 'actor_user_id' => $this->admin->id, 'occurred_at' => now()]);

        $this->getJson('/api/v1/admin/access-events')->assertOk()->assertJsonPath('data.0.channel', 'dashboard');

        $this->getJson("/api/v1/admin/access-events?device_id={$grant->lock_id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.grant.booking_id', $grant->source_id)
            ->assertJsonPath('data.0.actor.name', 'Lina');
    }

    public function test_door_codes_list_without_the_code_and_filter_to_live_ones(): void
    {
        $live = $this->bookingGrant();
        $this->bookingGrant(AccessGrantStatus::Expired);

        $response = $this->getJson('/api/v1/admin/access-grants?status=live')->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $live->id)
            ->assertJsonPath('data.0.grantee.name', 'Lina');
        $this->assertStringNotContainsString($live->passcode_value, $response->getContent());
    }

    public function test_staff_stop_a_booking_code_and_it_is_not_issued_again_on_its_own(): void
    {
        $grant = $this->bookingGrant();

        $this->postJson("/api/v1/admin/access-grants/{$grant->id}/revoke")->assertOk();

        $grant->refresh();
        $this->assertSame(AccessGrantStatus::Revoked, $grant->status);
        $this->assertSame(AccessRevokeReason::Staff, $grant->revoke_reason);
        $this->assertSame($this->admin->id, $grant->revoked_by);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v3/keyboardPwd/delete') && (int) $r['keyboardPwdId'] === 42);

        $this->artisan('access:issue-grants-on-cancellation-window-close')->assertSuccessful();
        $this->assertSame(1, AccessGrant::query()->where('source_id', $grant->source_id)->count());

        $this->postJson("/api/v1/admin/access-grants/{$grant->id}/revoke")->assertStatus(409);
    }

    public function test_an_office_code_is_not_stopped_by_hand(): void
    {
        $grant = AccessGrant::factory()->forCompany()->activated()->create(['lock_id' => $this->lock()->id]);

        $this->postJson("/api/v1/admin/access-grants/{$grant->id}/revoke")->assertStatus(422);

        $this->assertSame(AccessGrantStatus::Activated, $grant->fresh()->status);
    }

    public function test_the_desk_reads_a_bookings_code_timing_and_lock(): void
    {
        $grant = $this->bookingGrant(AccessGrantStatus::Issued);

        $this->getJson("/api/v1/admin/reception/bookings/{$grant->source_id}/access-grant")
            ->assertOk()
            ->assertJsonPath('id', $grant->id)
            ->assertJsonPath('status', 'issued')
            ->assertJsonPath('lock_id', $grant->lock_id)
            ->assertJsonMissingPath('passcode_value');
    }
}
