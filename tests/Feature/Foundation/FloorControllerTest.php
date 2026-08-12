<?php

namespace Tests\Feature\Foundation;

use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Floor;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FloorControllerTest extends TestCase
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

    public function test_admin_can_create_a_floor_and_sort_order_defaults_to_zero(): void
    {
        $this->actingAsAdmin();
        $building = Building::factory()->create();

        $response = $this->postJson('/api/v1/admin/floors', [
            'building_id' => $building->id,
            'label' => 'Ground',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.sort_order', 0);
        $this->assertDatabaseHas('floors', ['building_id' => $building->id, 'label' => 'Ground', 'sort_order' => 0]);
    }

    public function test_index_can_be_filtered_by_building_id(): void
    {
        $this->actingAsAdmin();
        $buildingA = Building::factory()->create();
        $buildingB = Building::factory()->create();
        Floor::factory()->for($buildingA)->create();
        Floor::factory()->for($buildingB)->create();

        $response = $this->getJson("/api/v1/admin/floors?building_id={$buildingA->id}");

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_admin_can_update_a_floor_and_gets_back_a_message(): void
    {
        $this->actingAsAdmin();
        $floor = Floor::factory()->create();

        $response = $this->withHeader('lang', 'en')->putJson("/api/v1/admin/floors/{$floor->id}", [
            'building_id' => $floor->building_id,
            'label' => 'Mezzanine',
            'sort_order' => 2,
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Floor updated.']);
        $this->assertSame('Mezzanine', $floor->fresh()->label);
    }

    public function test_admin_can_delete_a_floor(): void
    {
        $this->actingAsAdmin();
        $floor = Floor::factory()->create();

        $this->deleteJson("/api/v1/admin/floors/{$floor->id}")->assertNoContent();
        $this->assertDatabaseMissing('floors', ['id' => $floor->id]);
    }

    public function test_a_member_cannot_access_floor_admin_routes(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/admin/floors')->assertForbidden();
    }
}
