<?php

namespace Tests\Feature\Foundation;

use App\Domain\Foundation\Models\Resource;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ResourceControllerTest extends TestCase
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

    public function test_admin_can_create_a_resource_and_quantity_and_status_default(): void
    {
        $this->actingAsAdmin();
        $space = Space::factory()->create();

        $response = $this->postJson('/api/v1/admin/resources', [
            'space_id' => $space->id,
            'name' => 'Projector',
            'category' => 'projector',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.quantity', 1);
        $response->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('resources', ['space_id' => $space->id, 'quantity' => 1, 'status' => 'active']);
    }

    public function test_index_can_be_filtered_by_space_id(): void
    {
        $this->actingAsAdmin();
        $spaceA = Space::factory()->create();
        $spaceB = Space::factory()->create();
        Resource::factory()->for($spaceA)->create();
        Resource::factory()->for($spaceB)->create();

        $response = $this->getJson("/api/v1/admin/resources?space_id={$spaceA->id}");

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_admin_can_update_a_resource_without_touching_status(): void
    {
        $this->actingAsAdmin();
        $resource = Resource::factory()->create(['quantity' => 1, 'status' => 'active']);

        $response = $this->withHeader('lang', 'en')->putJson("/api/v1/admin/resources/{$resource->id}", [
            'space_id' => $resource->space_id,
            'name' => $resource->name,
            'category' => $resource->category->value,
            'quantity' => 3,
            'status' => 'retired',
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Resource updated.']);
        $this->assertSame(3, $resource->fresh()->quantity);
        $this->assertSame('active', $resource->fresh()->status->value);
    }

    public function test_admin_can_transition_resource_status_and_it_is_logged(): void
    {
        $admin = $this->actingAsAdmin();
        $resource = Resource::factory()->create();

        $response = $this->withHeader('lang', 'en')->patchJson("/api/v1/admin/resources/{$resource->id}/status", [
            'status' => 'maintenance',
            'status_reason' => 'Bulb replacement',
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Resource status updated.']);
        $this->assertSame('maintenance', $resource->fresh()->status->value);

        $activity = Activity::where('description', 'resource_status_changed')->latest('id')->first();
        $this->assertNotNull($activity);
        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame('active', $activity->properties['before']);
        $this->assertSame('maintenance', $activity->properties['after']);
    }

    public function test_admin_can_delete_a_resource(): void
    {
        $this->actingAsAdmin();
        $resource = Resource::factory()->create();

        $this->deleteJson("/api/v1/admin/resources/{$resource->id}")->assertNoContent();
        $this->assertDatabaseMissing('resources', ['id' => $resource->id]);
    }

    public function test_a_member_cannot_access_resource_admin_routes(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/admin/resources')->assertForbidden();
    }
}
