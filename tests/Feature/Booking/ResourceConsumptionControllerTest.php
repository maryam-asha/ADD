<?php

namespace Tests\Feature\Booking;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\ResourceType;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reception's manual-entry resource-consumption recording endpoint
 * (docs/decisions/resource-quota-design.md), next to WalletTopUpControllerTest.
 */
class ResourceConsumptionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function actingAsOperations(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        return $operator;
    }

    private function resourceType(): ResourceType
    {
        return ResourceType::create([
            'slug' => 'printing',
            'name' => ['en' => 'Printing', 'ar' => 'طباعة'],
            'unit_label' => ['en' => 'page', 'ar' => 'صفحة'],
        ]);
    }

    private function planWithGrant(ResourceType $resourceType, float $quantity = 100): Plan
    {
        $plan = Plan::factory()->create();
        $plan->resourceGrants()->create(['resource_type_id' => $resourceType->id, 'quantity' => $quantity]);

        return $plan;
    }

    private function activeMembershipFor(OwnerType $ownerType, int $ownerId, Plan $plan): Membership
    {
        return Membership::factory()->create([
            'plan_id' => $plan->id,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'status' => MembershipStatus::Active,
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(30),
        ]);
    }

    public function test_operations_can_record_consumption_for_a_member(): void
    {
        $operator = $this->actingAsOperations();
        $resourceType = $this->resourceType();
        $plan = $this->planWithGrant($resourceType, 100);
        $member = User::factory()->create();
        $this->activeMembershipFor(OwnerType::User, $member->id, $plan);

        $response = $this->postJson('/api/v1/admin/reception/resource-consumptions', [
            'user_id' => $member->id,
            'resource_type_id' => $resourceType->id,
            'quantity' => 12,
            'note' => 'Printed handouts',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.remaining', 88);
        $response->assertJsonPath('data.resource_type_id', $resourceType->id);

        $this->assertDatabaseHas('resource_consumptions', [
            'membership_id' => Membership::sole()->id,
            'recorded_by_user_id' => $operator->id,
        ]);
    }

    public function test_operations_can_record_consumption_for_a_company(): void
    {
        $this->actingAsOperations();
        $resourceType = $this->resourceType();
        $plan = $this->planWithGrant($resourceType, 50);
        $company = Company::factory()->create();
        $this->activeMembershipFor(OwnerType::Company, $company->id, $plan);

        $response = $this->postJson('/api/v1/admin/reception/resource-consumptions', [
            'company_id' => $company->id,
            'resource_type_id' => $resourceType->id,
            'quantity' => 5.5,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.remaining', 44.5);
    }

    public function test_consuming_more_than_what_remains_is_rejected(): void
    {
        $this->actingAsOperations();
        $resourceType = $this->resourceType();
        $plan = $this->planWithGrant($resourceType, 10);
        $member = User::factory()->create();
        $this->activeMembershipFor(OwnerType::User, $member->id, $plan);

        $response = $this->postJson('/api/v1/admin/reception/resource-consumptions', [
            'user_id' => $member->id,
            'resource_type_id' => $resourceType->id,
            'quantity' => 20,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('resource_consumptions', 0);
    }

    public function test_a_plan_with_no_grant_for_the_resource_type_is_rejected(): void
    {
        $this->actingAsOperations();
        $resourceType = $this->resourceType();
        $plan = Plan::factory()->create();
        $member = User::factory()->create();
        $this->activeMembershipFor(OwnerType::User, $member->id, $plan);

        $response = $this->postJson('/api/v1/admin/reception/resource-consumptions', [
            'user_id' => $member->id,
            'resource_type_id' => $resourceType->id,
            'quantity' => 1,
        ]);

        $response->assertStatus(422);
    }

    public function test_a_member_with_no_active_membership_is_rejected(): void
    {
        $this->actingAsOperations();
        $resourceType = $this->resourceType();
        $member = User::factory()->create();

        $response = $this->postJson('/api/v1/admin/reception/resource-consumptions', [
            'user_id' => $member->id,
            'resource_type_id' => $resourceType->id,
            'quantity' => 1,
        ]);

        $response->assertStatus(422);
    }

    public function test_a_member_cannot_record_resource_consumption(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $resourceType = $this->resourceType();

        $this->postJson('/api/v1/admin/reception/resource-consumptions', [
            'user_id' => $member->id,
            'resource_type_id' => $resourceType->id,
            'quantity' => 1,
        ])->assertForbidden();
    }

    public function test_guests_are_rejected(): void
    {
        $resourceType = $this->resourceType();
        $member = User::factory()->create();

        $this->postJson('/api/v1/admin/reception/resource-consumptions', [
            'user_id' => $member->id,
            'resource_type_id' => $resourceType->id,
            'quantity' => 1,
        ])->assertUnauthorized();
    }
}
