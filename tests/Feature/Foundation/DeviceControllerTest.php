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
            'external_ref' => '33804038',
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
            'external_ref' => '33804038',
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
            'external_ref' => '33804038',
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

        $response = $this->postJson('/api/v1/admin/devices', ['branch_id' => $branch->id, 'type' => 'lock', 'hardware_mac' => 'AA:BB:CC:DD:EE:01', 'external_ref' => '33804038']);

        $response->assertCreated();
        $device = Device::find($response->json('data.id'));
        $this->assertNotNull($device->qr_value);
        $this->assertSame(40, strlen($device->qr_value));
    }

    /**
     * Final-review C3a — operations needs qr_value/hardware_mac to print the
     * door sticker and parent_device_id to see which gateway a lock pairs
     * through; none of these were previously returned by DeviceResource.
     */
    public function test_device_response_includes_qr_value_hardware_mac_and_parent_device_id(): void
    {
        $this->actingAsAdmin();
        $gateway = Device::factory()->create(['type' => 'gateway', 'hardware_mac' => 'GW:00:00:00:00:01']);
        $lock = Device::factory()->create([
            'type' => 'lock',
            'hardware_mac' => 'AA:BB:CC:DD:EE:01',
            'parent_device_id' => $gateway->id,
            'qr_value' => 'sticker-value',
        ]);

        $response = $this->getJson("/api/v1/admin/devices?branch_id={$lock->branch_id}");

        $response->assertOk();
        $response->assertJsonFragment([
            'id' => $lock->id,
            'hardware_mac' => 'AA:BB:CC:DD:EE:01',
            'parent_device_id' => $gateway->id,
            'qr_value' => 'sticker-value',
        ]);
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

    public function test_a_lock_needs_its_ttlock_id_once_per_type_and_pairs_only_through_a_gateway(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();
        $camera = Device::factory()->create(['type' => 'camera']);
        Device::factory()->create(['type' => 'lock', 'external_ref' => '33804038']);
        $lock = fn (array $extra) => array_merge(['branch_id' => $branch->id, 'type' => 'lock', 'hardware_mac' => 'AA:BB:CC:DD:EE:09'], $extra);

        $this->postJson('/api/v1/admin/devices', $lock([]))->assertStatus(422)->assertJsonValidationErrors('external_ref');
        $this->postJson('/api/v1/admin/devices', $lock(['external_ref' => '33804038']))->assertStatus(422)->assertJsonValidationErrors('external_ref');
        $this->postJson('/api/v1/admin/devices', $lock(['external_ref' => '1', 'parent_device_id' => $camera->id]))
            ->assertStatus(422)->assertJsonValidationErrors('parent_device_id');
        // The same id on another type is another device.
        $this->postJson('/api/v1/admin/devices', ['branch_id' => $branch->id, 'type' => 'gateway', 'hardware_mac' => 'GW:01', 'external_ref' => '33804038'])
            ->assertCreated();
    }

    /** A standing rule (2026-11-08): the shared coworking space never has a lock. */
    public function test_a_lock_is_refused_on_the_coworking_space_and_taken_on_a_room(): void
    {
        $this->actingAsAdmin();
        $coworking = Space::factory()->create();
        $room = Space::factory()->room()->create();
        $lock = fn (Space $space, string $ref) => [
            'branch_id' => $space->building->branch_id, 'space_id' => $space->id,
            'type' => 'lock', 'hardware_mac' => "AA:{$ref}", 'external_ref' => $ref,
        ];

        $this->postJson('/api/v1/admin/devices', $lock($coworking, '1'))->assertStatus(422)->assertJsonValidationErrors('space_id');
        $this->postJson('/api/v1/admin/devices', $lock($room, '2'))->assertCreated();
    }

    public function test_updating_a_lock_keeps_its_own_ttlock_id(): void
    {
        $this->actingAsAdmin();
        $device = Device::factory()->create(['type' => 'lock', 'hardware_mac' => 'AA:01', 'external_ref' => '33804038']);

        $this->putJson("/api/v1/admin/devices/{$device->id}", [
            'branch_id' => $device->branch_id, 'type' => 'lock', 'hardware_mac' => 'AA:01', 'external_ref' => '33804038',
        ])->assertOk();
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
