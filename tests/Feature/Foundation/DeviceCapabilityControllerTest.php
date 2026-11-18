<?php

namespace Tests\Feature\Foundation;

use App\Domain\Foundation\Models\Device;
use App\Domain\Foundation\Models\DeviceCapability;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeviceCapabilityControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    private function actingAsOperations(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        return $operator;
    }

    public function test_admin_can_create_a_device_capability(): void
    {
        $this->actingAsAdmin();
        $device = Device::factory()->create();

        $response = $this->postJson('/api/v1/admin/device-capabilities', [
            'device_id' => $device->id,
            'capability' => 'revoke_passcode',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('device_capabilities', ['device_id' => $device->id, 'capability' => 'revoke_passcode']);
    }

    public function test_index_can_be_filtered_by_device_id(): void
    {
        $this->actingAsAdmin();
        $deviceA = Device::factory()->create();
        $deviceB = Device::factory()->create();
        DeviceCapability::factory()->for($deviceA)->create();
        DeviceCapability::factory()->for($deviceB)->create();

        $response = $this->getJson("/api/v1/admin/device-capabilities?device_id={$deviceA->id}");

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_admin_can_update_a_device_capability_and_gets_back_a_message(): void
    {
        $this->actingAsAdmin();
        $capability = DeviceCapability::factory()->create();

        $response = $this->withHeader('lang', 'en')->putJson("/api/v1/admin/device-capabilities/{$capability->id}", [
            'device_id' => $capability->device_id,
            'capability' => 'stream',
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Device capability updated.']);
        $this->assertSame('stream', $capability->fresh()->capability);
    }

    public function test_admin_can_delete_a_device_capability(): void
    {
        $this->actingAsAdmin();
        $capability = DeviceCapability::factory()->create();

        $this->deleteJson("/api/v1/admin/device-capabilities/{$capability->id}")->assertNoContent();
        $this->assertDatabaseMissing('device_capabilities', ['id' => $capability->id]);
    }

    public function test_operations_cannot_delete_a_device_capability(): void
    {
        $this->actingAsOperations();
        $capability = DeviceCapability::factory()->create();

        $this->deleteJson("/api/v1/admin/device-capabilities/{$capability->id}")->assertForbidden();
        $this->assertDatabaseHas('device_capabilities', ['id' => $capability->id]);
    }

    public function test_a_member_cannot_access_device_capability_admin_routes(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/admin/device-capabilities')->assertForbidden();
    }
}
