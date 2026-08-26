<?php

namespace Tests\Feature\Foundation;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Device;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeviceControllerTest extends TestCase
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

    public function test_admin_can_create_a_device_and_status_defaults_to_offline(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $response = $this->postJson('/api/v1/admin/devices', [
            'branch_id' => $branch->id,
            'type' => 'lock',
            'hardware_mac' => 'AA:BB:CC:DD:EE:01',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'offline');
        $this->assertDatabaseHas('devices', ['branch_id' => $branch->id, 'type' => 'lock', 'status' => 'offline']);
    }

    public function test_admin_can_create_a_device_with_explicit_null_status_and_it_still_defaults_to_offline(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $response = $this->postJson('/api/v1/admin/devices', [
            'branch_id' => $branch->id,
            'type' => 'lock',
            'hardware_mac' => 'AA:BB:CC:DD:EE:01',
            'status' => null,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'offline');
        $this->assertDatabaseHas('devices', ['branch_id' => $branch->id, 'type' => 'lock', 'status' => 'offline']);
    }

    public function test_index_can_be_filtered_by_branch_id_and_space_id(): void
    {
        $this->actingAsAdmin();
        $space = Space::factory()->create();
        $matching = Device::factory()->for($space)->create(['branch_id' => $space->building->branch_id]);
        Device::factory()->create();

        $response = $this->getJson("/api/v1/admin/devices?branch_id={$matching->branch_id}&space_id={$space->id}");

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_admin_can_update_a_device_and_gets_back_a_message(): void
    {
        $this->actingAsAdmin();
        $device = Device::factory()->create();

        $response = $this->withHeader('lang', 'en')->putJson("/api/v1/admin/devices/{$device->id}", [
            'branch_id' => $device->branch_id,
            'type' => $device->type,
            'hardware_mac' => 'AA:BB:CC:DD:EE:01',
            'status' => 'online',
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Device updated.']);
        $this->assertSame('online', $device->fresh()->status);
    }

    public function test_admin_can_delete_a_device(): void
    {
        $this->actingAsAdmin();
        $device = Device::factory()->create();

        $this->deleteJson("/api/v1/admin/devices/{$device->id}")->assertNoContent();
        $this->assertDatabaseMissing('devices', ['id' => $device->id]);
    }

    public function test_operations_cannot_delete_a_device(): void
    {
        $this->actingAsOperations();
        $device = Device::factory()->create();

        $this->deleteJson("/api/v1/admin/devices/{$device->id}")->assertForbidden();
        $this->assertDatabaseHas('devices', ['id' => $device->id]);
    }

    public function test_a_member_cannot_access_device_admin_routes(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/admin/devices')->assertForbidden();
    }

    public function test_creating_a_lock_device_auto_generates_a_qr_value(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $response = $this->postJson('/api/v1/admin/devices', ['branch_id' => $branch->id, 'type' => 'lock', 'hardware_mac' => 'AA:BB:CC:DD:EE:01']);

        $response->assertCreated();
        $device = Device::find($response->json('data.id'));
        $this->assertNotNull($device->qr_value);
        $this->assertSame(40, strlen($device->qr_value));
    }

    public function test_creating_a_camera_device_does_not_generate_a_qr_value(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $response = $this->postJson('/api/v1/admin/devices', ['branch_id' => $branch->id, 'type' => 'camera']);

        $response->assertCreated();
        $this->assertNull(Device::find($response->json('data.id'))->qr_value);
    }

    public function test_creating_a_lock_without_hardware_mac_fails_validation(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $this->postJson('/api/v1/admin/devices', ['branch_id' => $branch->id, 'type' => 'lock', 'hardware_mac' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('hardware_mac');
    }

    public function test_admin_can_regenerate_a_locks_qr_value(): void
    {
        $this->actingAsAdmin();
        $device = Device::factory()->create(['type' => 'lock', 'qr_value' => 'old-value']);

        $response = $this->postJson("/api/v1/admin/devices/{$device->id}/regenerate-qr-value");

        $response->assertOk();
        $device->refresh();
        $this->assertNotSame('old-value', $device->qr_value);
        $this->assertSame(40, strlen($device->qr_value));
    }

    public function test_regenerating_qr_value_on_a_non_lock_device_is_rejected(): void
    {
        $this->actingAsAdmin();
        $device = Device::factory()->create(['type' => 'camera']);

        $this->postJson("/api/v1/admin/devices/{$device->id}/regenerate-qr-value")->assertStatus(422);
    }
}
