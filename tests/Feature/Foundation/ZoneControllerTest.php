<?php

namespace Tests\Feature\Foundation;

use App\Domain\Foundation\Models\Floor;
use App\Domain\Foundation\Models\Zone;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ZoneControllerTest extends TestCase
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

    public function test_admin_can_create_a_zone_and_sort_order_defaults_to_zero(): void
    {
        $this->actingAsAdmin();
        $floor = Floor::factory()->create();

        $response = $this->postJson('/api/v1/admin/zones', [
            'floor_id' => $floor->id,
            'label' => 'Zone A',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.sort_order', 0);
        $this->assertDatabaseHas('zones', ['floor_id' => $floor->id, 'label' => 'Zone A']);
    }

    public function test_admin_can_create_a_zone_with_explicit_null_sort_order_and_it_still_defaults_to_zero(): void
    {
        $this->actingAsAdmin();
        $floor = Floor::factory()->create();

        $response = $this->postJson('/api/v1/admin/zones', [
            'floor_id' => $floor->id,
            'label' => 'Zone A',
            'sort_order' => null,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.sort_order', 0);
        $this->assertDatabaseHas('zones', ['floor_id' => $floor->id, 'label' => 'Zone A', 'sort_order' => 0]);
    }

    public function test_index_can_be_filtered_by_floor_id(): void
    {
        $this->actingAsAdmin();
        $floorA = Floor::factory()->create();
        $floorB = Floor::factory()->create();
        Zone::factory()->for($floorA)->create();
        Zone::factory()->for($floorB)->create();

        $response = $this->getJson("/api/v1/admin/zones?floor_id={$floorA->id}");

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_admin_can_update_a_zone_and_gets_back_a_message(): void
    {
        $this->actingAsAdmin();
        $zone = Zone::factory()->create();

        $response = $this->withHeader('lang', 'en')->putJson("/api/v1/admin/zones/{$zone->id}", [
            'floor_id' => $zone->floor_id,
            'label' => 'Zone B',
            'sort_order' => 1,
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Zone updated.']);
        $this->assertSame('Zone B', $zone->fresh()->label);
    }

    public function test_admin_can_delete_a_zone(): void
    {
        $this->actingAsAdmin();
        $zone = Zone::factory()->create();

        $this->deleteJson("/api/v1/admin/zones/{$zone->id}")->assertNoContent();
        $this->assertDatabaseMissing('zones', ['id' => $zone->id]);
    }

    public function test_operations_cannot_delete_a_zone(): void
    {
        $this->actingAsOperations();
        $zone = Zone::factory()->create();

        $this->deleteJson("/api/v1/admin/zones/{$zone->id}")->assertForbidden();
        $this->assertDatabaseHas('zones', ['id' => $zone->id]);
    }

    public function test_a_member_cannot_access_zone_admin_routes(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/admin/zones')->assertForbidden();
    }
}
