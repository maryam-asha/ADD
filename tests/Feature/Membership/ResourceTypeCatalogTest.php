<?php

namespace Tests\Feature\Membership;

use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\ResourceType;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ResourceType is a small standalone admin catalog with no Public
 * counterpart — same shape as PlanModule (docs/decisions/resource-quota-design.md).
 */
class ResourceTypeCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => ['en' => 'Printing', 'ar' => 'طباعة'],
            'unit_label' => ['en' => 'page', 'ar' => 'صفحة'],
            'icon' => 'printer',
            'order' => 1,
            'is_active' => true,
        ], $overrides);
    }

    public function test_admin_can_create_list_show_update_and_delete_a_resource_type(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $createResponse = $this->postJson('/api/v1/admin/resource-types', $this->payload());

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('data.name.en', 'Printing');
        $createResponse->assertJsonPath('data.unit_label.en', 'page');
        $createResponse->assertJsonPath('data.slug', 'printing');

        $resourceTypeId = $createResponse->json('data.id');

        $this->getJson('/api/v1/admin/resource-types')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/admin/resource-types/{$resourceTypeId}")
            ->assertOk()
            ->assertJsonPath('data.id', $resourceTypeId);

        $updateResponse = $this->withHeader('lang', 'en')->putJson("/api/v1/admin/resource-types/{$resourceTypeId}", $this->payload([
            'name' => ['en' => 'Printing & Scanning', 'ar' => 'طباعة ومسح'],
        ]));

        $updateResponse->assertOk();
        $updateResponse->assertExactJson(['message' => 'Resource type updated.']);
        $this->assertDatabaseHas('resource_types', [
            'id' => $resourceTypeId,
        ]);

        $this->deleteJson("/api/v1/admin/resource-types/{$resourceTypeId}")->assertNoContent();
        $this->assertDatabaseCount('resource_types', 0);
    }

    public function test_operations_can_manage_resource_types_too(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $this->postJson('/api/v1/admin/resource-types', $this->payload())->assertCreated();
    }

    public function test_a_member_cannot_manage_resource_types(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->postJson('/api/v1/admin/resource-types', $this->payload())->assertForbidden();
    }

    public function test_guests_are_rejected_from_admin_resource_type_routes(): void
    {
        $this->postJson('/api/v1/admin/resource-types', $this->payload())->assertUnauthorized();
    }

    public function test_slug_auto_generates_from_name_and_stays_unique(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $payload = $this->payload();
        unset($payload['slug']);

        $first = $this->postJson('/api/v1/admin/resource-types', $payload);
        $first->assertCreated();
        $first->assertJsonPath('data.slug', 'printing');

        $second = $this->postJson('/api/v1/admin/resource-types', $payload);
        $second->assertCreated();
        $second->assertJsonPath('data.slug', 'printing-1');
    }

    public function test_deleting_a_resource_type_in_use_by_a_plan_returns_a_clean_422(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $resourceType = ResourceType::create([
            'slug' => 'internet',
            'name' => ['en' => 'Internet', 'ar' => 'إنترنت'],
            'unit_label' => ['en' => 'hour', 'ar' => 'ساعة'],
        ]);

        $plan = Plan::factory()->create();
        $plan->resourceGrants()->create([
            'resource_type_id' => $resourceType->id,
            'quantity' => 10,
        ]);

        $response = $this->deleteJson("/api/v1/admin/resource-types/{$resourceType->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('resource_types', ['id' => $resourceType->id]);
    }
}
