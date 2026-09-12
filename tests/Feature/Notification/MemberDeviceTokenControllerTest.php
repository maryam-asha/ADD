<?php

namespace Tests\Feature\Notification;

use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MemberDeviceTokenControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_member_can_update_device_token(): void
    {
        $member = User::factory()->create(['fcm_token' => null]);
        $member->assignRole('member');
        Sanctum::actingAs($member, ['member-app']);

        $response = $this->putJson('/api/v1/member/device-token', [
            'fcm_token' => 'test-fcm-token-abc123',
            'platform' => 'ios',
        ]);

        $response->assertOk();
        $response->assertJson(['message' => 'Device token updated.']);

        $this->assertEquals('test-fcm-token-abc123', $member->fresh()->fcm_token);
        $this->assertDatabaseHas('device_tokens', [
            'user_id' => $member->id,
            'token' => 'test-fcm-token-abc123',
            'platform' => 'ios',
        ]);
    }

    public function test_member_can_register_multiple_devices(): void
    {
        $member = User::factory()->create(['fcm_token' => null]);
        $member->assignRole('member');
        Sanctum::actingAs($member, ['member-app']);

        $this->putJson('/api/v1/member/device-token', [
            'token' => 'device-token-iphone',
            'platform' => 'ios',
        ])->assertOk();

        $this->putJson('/api/v1/member/device-token', [
            'token' => 'device-token-ipad',
            'platform' => 'ios',
        ])->assertOk();

        $this->assertEquals(2, $member->deviceTokens()->count());
    }

    public function test_member_can_remove_specific_device_token(): void
    {
        $member = User::factory()->create(['fcm_token' => 'token-1']);
        $member->assignRole('member');
        Sanctum::actingAs($member, ['member-app']);

        $member->deviceTokens()->createMany([
            ['token' => 'token-1', 'platform' => 'ios'],
            ['token' => 'token-2', 'platform' => 'android'],
        ]);

        $response = $this->deleteJson('/api/v1/member/device-token', [
            'token' => 'token-1',
        ]);

        $response->assertOk();
        $response->assertJson(['message' => 'Device token removed.']);

        $this->assertDatabaseMissing('device_tokens', ['token' => 'token-1']);
        $this->assertDatabaseHas('device_tokens', ['token' => 'token-2']);
    }

    public function test_member_can_remove_all_device_tokens_on_logout(): void
    {
        $member = User::factory()->create(['fcm_token' => 'existing-token']);
        $member->assignRole('member');
        Sanctum::actingAs($member, ['member-app']);

        $member->deviceTokens()->create(['token' => 'existing-token']);

        $response = $this->deleteJson('/api/v1/member/device-token');

        $response->assertOk();
        $response->assertJson(['message' => 'Device token removed.']);

        $this->assertNull($member->fresh()->fcm_token);
        $this->assertEquals(0, $member->deviceTokens()->count());
    }

    public function test_device_token_is_required(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['member-app']);

        $response = $this->putJson('/api/v1/member/device-token', []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('fcm_token');
    }

    public function test_device_token_must_be_string(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['member-app']);

        $response = $this->putJson('/api/v1/member/device-token', [
            'fcm_token' => 12345,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('fcm_token');
    }
}
