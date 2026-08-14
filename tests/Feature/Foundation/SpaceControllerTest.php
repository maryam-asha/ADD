<?php

namespace Tests\Feature\Foundation;

use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
use App\Domain\Foundation\Models\Zone;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class SpaceControllerTest extends TestCase
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

    public function test_admin_can_create_a_space_and_status_defaults_to_active(): void
    {
        $this->actingAsAdmin();
        $building = Building::factory()->create();

        $response = $this->postJson('/api/v1/admin/spaces', [
            'building_id' => $building->id,
            'space_type' => 'room',
            'is_lockable' => true,
            'capacity' => 4,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('spaces', ['building_id' => $building->id, 'status' => 'active']);
    }

    public function test_index_can_be_filtered_by_building_id_and_zone_id(): void
    {
        $admin = $this->actingAsAdmin();
        $zone = Zone::factory()->create();
        $matching = Space::factory()->create([
            'building_id' => $zone->floor->building_id,
            'zone_id' => $zone->id,
        ]);
        Space::factory()->create();

        $response = $this->getJson("/api/v1/admin/spaces?building_id={$matching->building_id}&zone_id={$zone->id}");

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_index_can_be_paginated(): void
    {
        $this->actingAsAdmin();
        Space::factory()->count(8)->create();

        $response = $this->getJson('/api/v1/admin/spaces?per_page=3');

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
        $response->assertJsonPath('meta.total', 8);
        $response->assertJsonPath('meta.per_page', 3);
        $response->assertJsonPath('links.next', fn ($url) => str_contains($url, 'page=2'));
    }

    public function test_admin_can_update_structural_fields_without_touching_status(): void
    {
        $this->actingAsAdmin();
        $space = Space::factory()->create(['capacity' => 4]);

        $response = $this->withHeader('lang', 'en')->putJson("/api/v1/admin/spaces/{$space->id}", [
            'building_id' => $space->building_id,
            'zone_id' => $space->zone_id,
            'space_type' => $space->space_type->value,
            'is_lockable' => $space->is_lockable,
            'capacity' => 10,
            'status' => 'retired',
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Space updated.']);
        $this->assertSame(10, $space->fresh()->capacity);
        $this->assertSame('active', $space->fresh()->status->value);
    }

    public function test_admin_can_transition_space_status_and_it_is_logged(): void
    {
        $admin = $this->actingAsAdmin();
        $space = Space::factory()->create();

        $response = $this->withHeader('lang', 'en')->patchJson("/api/v1/admin/spaces/{$space->id}/status", [
            'status' => 'maintenance',
            'status_reason' => 'Carpet replacement',
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Space status updated.']);
        $this->assertSame('maintenance', $space->fresh()->status->value);

        $activity = Activity::where('description', 'space_status_changed')->latest('id')->first();
        $this->assertNotNull($activity);
        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame('active', $activity->properties['before']);
        $this->assertSame('maintenance', $activity->properties['after']);
    }

    public function test_admin_can_delete_a_space(): void
    {
        $this->actingAsAdmin();
        $space = Space::factory()->create();

        $this->deleteJson("/api/v1/admin/spaces/{$space->id}")->assertNoContent();
        $this->assertDatabaseMissing('spaces', ['id' => $space->id]);
    }

    public function test_operations_cannot_delete_a_space(): void
    {
        $this->actingAsOperations();
        $space = Space::factory()->create();

        $this->deleteJson("/api/v1/admin/spaces/{$space->id}")->assertForbidden();
        $this->assertDatabaseHas('spaces', ['id' => $space->id]);
    }

    public function test_a_member_cannot_access_space_admin_routes(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/admin/spaces')->assertForbidden();
    }
}
