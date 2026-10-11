<?php

namespace Tests\Feature\Membership;

use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Exceptions\InsufficientResourceQuotaException;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\ResourceConsumption;
use App\Domain\Membership\Models\ResourceType;
use App\Domain\Membership\Services\ResourceQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The read-time quota computation and ledger-writing behaviour of
 * ResourceQuotaService (docs/decisions/resource-quota-design.md) — the
 * natural-unit sibling of WalletService's balance mechanics.
 */
class ResourceQuotaServiceTest extends TestCase
{
    use RefreshDatabase;

    private function resourceType(): ResourceType
    {
        return ResourceType::create([
            'slug' => 'printing',
            'name' => ['en' => 'Printing', 'ar' => 'طباعة'],
            'unit_label' => ['en' => 'page', 'ar' => 'صفحة'],
        ]);
    }

    private function activeMembership(Plan $plan): Membership
    {
        $member = User::factory()->create();

        return Membership::factory()->create([
            'plan_id' => $plan->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(30),
        ]);
    }

    public function test_remaining_for_is_null_when_the_plan_has_no_grant(): void
    {
        $resourceType = $this->resourceType();
        $plan = Plan::factory()->create();
        $membership = $this->activeMembership($plan);

        $remaining = app(ResourceQuotaService::class)->remainingFor($membership, $resourceType);

        $this->assertNull($remaining);
    }

    public function test_remaining_for_is_the_full_quantity_with_no_consumption(): void
    {
        $resourceType = $this->resourceType();
        $plan = Plan::factory()->create();
        $plan->resourceGrants()->create(['resource_type_id' => $resourceType->id, 'quantity' => 100]);
        $membership = $this->activeMembership($plan);

        $remaining = app(ResourceQuotaService::class)->remainingFor($membership, $resourceType);

        $this->assertSame(100.0, $remaining);
    }

    public function test_remaining_for_subtracts_recorded_consumption(): void
    {
        $resourceType = $this->resourceType();
        $plan = Plan::factory()->create();
        $grant = $plan->resourceGrants()->create(['resource_type_id' => $resourceType->id, 'quantity' => 100]);
        $membership = $this->activeMembership($plan);
        $recordedBy = User::factory()->create();

        $quota = app(ResourceQuotaService::class);
        $quota->consume($grant, $membership, 12, $recordedBy);
        $quota->consume($grant, $membership, 8, $recordedBy);

        $this->assertSame(80.0, $quota->remainingFor($membership, $resourceType));
    }

    public function test_consume_creates_a_ledger_row_and_updates_remaining(): void
    {
        $resourceType = $this->resourceType();
        $plan = Plan::factory()->create();
        $grant = $plan->resourceGrants()->create(['resource_type_id' => $resourceType->id, 'quantity' => 50]);
        $membership = $this->activeMembership($plan);
        $recordedBy = User::factory()->create();

        $quota = app(ResourceQuotaService::class);
        $consumption = $quota->consume($grant, $membership, 15, $recordedBy, 'Printed handouts');

        $this->assertInstanceOf(ResourceConsumption::class, $consumption);
        $this->assertSame($grant->id, $consumption->plan_resource_grant_id);
        $this->assertSame($membership->id, $consumption->membership_id);
        $this->assertSame(15.0, (float) $consumption->quantity);
        $this->assertSame($recordedBy->id, $consumption->recorded_by_user_id);
        $this->assertSame('Printed handouts', $consumption->note);
        $this->assertDatabaseCount('resource_consumptions', 1);
        $this->assertSame(35.0, $quota->remainingFor($membership, $resourceType));
    }

    public function test_consume_throws_and_writes_nothing_when_the_amount_exceeds_what_remains(): void
    {
        $resourceType = $this->resourceType();
        $plan = Plan::factory()->create();
        $grant = $plan->resourceGrants()->create(['resource_type_id' => $resourceType->id, 'quantity' => 10]);
        $membership = $this->activeMembership($plan);
        $recordedBy = User::factory()->create();

        $quota = app(ResourceQuotaService::class);

        $this->expectException(InsufficientResourceQuotaException::class);

        try {
            $quota->consume($grant, $membership, 20, $recordedBy);
        } finally {
            $this->assertDatabaseCount('resource_consumptions', 0);
        }
    }

    public function test_a_membership_renewal_resets_remaining_to_the_full_grant(): void
    {
        $resourceType = $this->resourceType();
        $plan = Plan::factory()->create();
        $grant = $plan->resourceGrants()->create(['resource_type_id' => $resourceType->id, 'quantity' => 40]);
        $oldMembership = $this->activeMembership($plan);
        $recordedBy = User::factory()->create();

        $quota = app(ResourceQuotaService::class);
        $quota->consume($grant, $oldMembership, 30, $recordedBy);
        $this->assertSame(10.0, $quota->remainingFor($oldMembership, $resourceType));

        // A renewal is a brand-new Membership row on the same plan.
        $newMembership = Membership::factory()->create([
            'plan_id' => $plan->id,
            'owner_type' => $oldMembership->owner_type,
            'owner_id' => $oldMembership->owner_id,
            'status' => MembershipStatus::Active,
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(30),
        ]);

        $this->assertSame(40.0, $quota->remainingFor($newMembership, $resourceType));
        // The old membership's consumption history is untouched.
        $this->assertSame(10.0, $quota->remainingFor($oldMembership, $resourceType));
    }
}
