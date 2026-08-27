<?php

namespace Tests\Feature\Access;

use App\Domain\Access\Enums\AccessEventChannel;
use App\Domain\Access\Enums\AccessEventType;
use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Models\Device;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UnlockViaQrTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        config(['services.ttlock.base_url' => 'https://api.sciener.test']);
        Http::fake(['api.sciener.test/oauth2/token' => Http::response([
            'access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 7776000,
        ], 200)]);
    }

    private function actingAsMember(): User
    {
        $user = User::factory()->create();
        $user->assignRole('member');
        Sanctum::actingAs($user, ['member-app']);

        return $user;
    }

    public function test_activated_grant_for_the_scanning_user_unlocks_and_logs_qr_scan(): void
    {
        Http::fake(['api.sciener.test/v3/lock/unlock' => Http::response(['errcode' => 0, 'errmsg' => ''], 200)]);
        $user = $this->actingAsMember();
        $lock = Device::factory()->create(['type' => 'lock', 'qr_value' => 'sticker-1']);
        AccessGrant::factory()->activated()->create([
            'lock_id' => $lock->id, 'grantee_type' => 'user', 'grantee_id' => $user->id,
            'expires_at' => now()->addHour(),
        ]);

        $response = $this->postJson('/api/v1/member/access/unlock', ['qr_value' => 'sticker-1']);

        $response->assertOk();
        $this->assertDatabaseHas('access_events', [
            'device_id' => $lock->id, 'event_type' => AccessEventType::Unlock->value, 'channel' => AccessEventChannel::QrScan->value,
        ]);
    }

    public function test_expired_grant_is_denied_and_logs_failed_attempt(): void
    {
        $user = $this->actingAsMember();
        $lock = Device::factory()->create(['type' => 'lock', 'qr_value' => 'sticker-1']);
        AccessGrant::factory()->activated()->create([
            'lock_id' => $lock->id, 'grantee_type' => 'user', 'grantee_id' => $user->id,
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/v1/member/access/unlock', ['qr_value' => 'sticker-1'])->assertStatus(403);
        $this->assertDatabaseHas('access_events', ['device_id' => $lock->id, 'event_type' => AccessEventType::FailedAttempt->value]);
    }

    public function test_revoked_grant_is_denied(): void
    {
        $user = $this->actingAsMember();
        $lock = Device::factory()->create(['type' => 'lock', 'qr_value' => 'sticker-1']);
        AccessGrant::factory()->revoked()->create(['lock_id' => $lock->id, 'grantee_type' => 'user', 'grantee_id' => $user->id]);

        $this->postJson('/api/v1/member/access/unlock', ['qr_value' => 'sticker-1'])->assertStatus(403);
    }

    public function test_not_yet_activated_grant_is_denied(): void
    {
        $user = $this->actingAsMember();
        $lock = Device::factory()->create(['type' => 'lock', 'qr_value' => 'sticker-1']);
        AccessGrant::factory()->create(['lock_id' => $lock->id, 'grantee_type' => 'user', 'grantee_id' => $user->id, 'status' => AccessGrantStatus::Issued]);

        $this->postJson('/api/v1/member/access/unlock', ['qr_value' => 'sticker-1'])->assertStatus(403);
    }

    public function test_user_with_no_grant_for_this_lock_is_denied(): void
    {
        $user = $this->actingAsMember();
        Device::factory()->create(['type' => 'lock', 'qr_value' => 'sticker-1']);

        $this->postJson('/api/v1/member/access/unlock', ['qr_value' => 'sticker-1'])->assertStatus(403);
    }

    public function test_maintenance_revoked_grant_is_denied_even_if_activated_moments_earlier(): void
    {
        $user = $this->actingAsMember();
        $lock = Device::factory()->create(['type' => 'lock', 'qr_value' => 'sticker-1']);
        AccessGrant::factory()->create([
            'lock_id' => $lock->id, 'grantee_type' => 'user', 'grantee_id' => $user->id,
            'status' => AccessGrantStatus::Revoked, 'activated_at' => now()->subSeconds(5), 'expires_at' => now()->addHour(),
        ]);

        $this->postJson('/api/v1/member/access/unlock', ['qr_value' => 'sticker-1'])->assertStatus(403);
    }

    /**
     * Defense-in-depth half of final-review C1 — the primary fix is the
     * scheduled RevokeAccessGrantsOnBookingCancellation command, but a
     * grant can still be `Activated` for a few minutes between
     * cancellation and the next run. UnlockService::activeGrantFor() must
     * not treat it as usable regardless.
     */
    public function test_activated_grant_whose_booking_is_cancelled_is_denied(): void
    {
        $user = $this->actingAsMember();
        $lock = Device::factory()->create(['type' => 'lock', 'qr_value' => 'sticker-1']);
        $booking = Booking::factory()->cancelled()->create();
        AccessGrant::factory()->activated()->create([
            'lock_id' => $lock->id, 'grantee_type' => 'user', 'grantee_id' => $user->id,
            'source_id' => $booking->id, 'expires_at' => now()->addHour(),
        ]);

        $this->postJson('/api/v1/member/access/unlock', ['qr_value' => 'sticker-1'])->assertStatus(403);
        $this->assertDatabaseHas('access_events', ['device_id' => $lock->id, 'event_type' => AccessEventType::FailedAttempt->value]);
    }

    public function test_company_tenancy_grant_works_for_a_member_with_door_access_enabled(): void
    {
        $user = $this->actingAsMember();
        $company = Company::factory()->create();
        $company->members()->attach($user->id, ['door_access_enabled' => true, 'is_admin' => false]);
        $lock = Device::factory()->create(['type' => 'lock', 'qr_value' => 'sticker-1']);
        AccessGrant::factory()->forCompany()->activated()->create(['lock_id' => $lock->id, 'grantee_id' => $company->id]);
        Http::fake(['api.sciener.test/v3/lock/unlock' => Http::response(['errcode' => 0, 'errmsg' => ''], 200)]);

        $this->postJson('/api/v1/member/access/unlock', ['qr_value' => 'sticker-1'])->assertOk();
    }

    public function test_company_member_without_door_access_enabled_is_denied(): void
    {
        $user = $this->actingAsMember();
        $company = Company::factory()->create();
        $company->members()->attach($user->id, ['door_access_enabled' => false, 'is_admin' => false]);
        $lock = Device::factory()->create(['type' => 'lock', 'qr_value' => 'sticker-1']);
        AccessGrant::factory()->forCompany()->activated()->create(['lock_id' => $lock->id, 'grantee_id' => $company->id]);

        $this->postJson('/api/v1/member/access/unlock', ['qr_value' => 'sticker-1'])->assertStatus(403);
    }

    public function test_a_connection_exception_reaching_ttlock_is_handled_gracefully_not_a_500(): void
    {
        Http::fake([
            'api.sciener.test/v3/lock/unlock' => fn () => throw new ConnectionException('Connection timed out'),
        ]);
        $user = $this->actingAsMember();
        $lock = Device::factory()->create(['type' => 'lock', 'qr_value' => 'sticker-1']);
        AccessGrant::factory()->activated()->create([
            'lock_id' => $lock->id, 'grantee_type' => 'user', 'grantee_id' => $user->id, 'expires_at' => now()->addHour(),
        ]);

        $response = $this->postJson('/api/v1/member/access/unlock', ['qr_value' => 'sticker-1']);

        $response->assertStatus(503);
        $response->assertJsonFragment(['message' => __('api.access.unlock_failed')]);
        $this->assertDatabaseHas('access_events', ['device_id' => $lock->id, 'event_type' => AccessEventType::FailedAttempt->value]);
    }

    public function test_unknown_qr_value_returns_404(): void
    {
        $this->actingAsMember();

        $this->postJson('/api/v1/member/access/unlock', ['qr_value' => 'does-not-exist'])->assertStatus(404);
    }

    public function test_gateway_offline_returns_a_distinguishable_message(): void
    {
        Http::fake(['api.sciener.test/v3/lock/unlock' => Http::response(['errcode' => -2012, 'errmsg' => 'The Lock is not connected to any Gateway.'], 200)]);
        $user = $this->actingAsMember();
        $lock = Device::factory()->create(['type' => 'lock', 'qr_value' => 'sticker-1']);
        AccessGrant::factory()->activated()->create(['lock_id' => $lock->id, 'grantee_type' => 'user', 'grantee_id' => $user->id, 'expires_at' => now()->addHour()]);

        $response = $this->postJson('/api/v1/member/access/unlock', ['qr_value' => 'sticker-1']);

        $response->assertStatus(503);
        $response->assertJsonFragment(['message' => __('api.access.gateway_offline')]);
        $this->assertDatabaseHas('access_events', ['device_id' => $lock->id, 'event_type' => AccessEventType::FailedAttempt->value]);
    }
}
