<?php

namespace Tests\Feature\Foundation;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BuildingControllerTest extends TestCase
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

    public function test_admin_can_create_a_building(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $response = $this->postJson('/api/v1/admin/buildings', [
            'branch_id' => $branch->id,
            'name' => ['ar' => 'المبنى الأول', 'en' => 'Building One'],
            'floor_count' => 4,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('buildings', ['branch_id' => $branch->id, 'floor_count' => 4]);
    }

    public function test_index_can_be_filtered_by_branch_id(): void
    {
        $this->actingAsAdmin();
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        Building::factory()->for($branchA)->create();
        Building::factory()->for($branchB)->create();

        $response = $this->getJson("/api/v1/admin/buildings?branch_id={$branchA->id}");

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.branch_id', $branchA->id);
    }

    public function test_index_without_a_filter_returns_every_building(): void
    {
        $this->actingAsAdmin();
        Building::factory()->count(3)->create();

        $this->getJson('/api/v1/admin/buildings')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_admin_can_update_a_building_and_gets_back_a_message(): void
    {
        $this->actingAsAdmin();
        $building = Building::factory()->create();

        $response = $this->withHeader('lang', 'en')->putJson("/api/v1/admin/buildings/{$building->id}", [
            'branch_id' => $building->branch_id,
            'name' => $building->name,
            'floor_count' => 9,
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Building updated.']);
        $this->assertSame(9, $building->fresh()->floor_count);
    }

    public function test_admin_can_delete_a_building(): void
    {
        $this->actingAsAdmin();
        $building = Building::factory()->create();

        $this->deleteJson("/api/v1/admin/buildings/{$building->id}")->assertNoContent();
        $this->assertDatabaseMissing('buildings', ['id' => $building->id]);
    }

    public function test_a_member_cannot_access_building_admin_routes(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/admin/buildings')->assertForbidden();
    }
}
