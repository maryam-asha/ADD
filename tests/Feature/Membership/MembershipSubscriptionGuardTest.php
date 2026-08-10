<?php

namespace Tests\Feature\Membership;

use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Behavioral regression coverage for the Phase 3 build-plan guard "a
 * is_subscription=false plan can never create a membership row"
 * (App\Domain\Membership\Models\Membership::booted()). Proves the model
 * itself refuses this independent of the HTTP layer — see
 * MembershipPurchaseTest::test_a_one_time_package_plan_cannot_be_purchased_as_a_membership
 * for the corresponding 422-level proof that the Form Request also rejects it.
 */
class MembershipSubscriptionGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_membership_directly_for_a_non_subscription_plan_throws(): void
    {
        $plan = Plan::factory()->create(['is_subscription' => false]);

        $this->expectException(\InvalidArgumentException::class);

        Membership::create([
            'plan_id' => $plan->id,
            'owner_type' => OwnerType::User,
            'owner_id' => 1,
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(30),
        ]);
    }

    public function test_creating_a_membership_directly_for_a_subscription_plan_succeeds(): void
    {
        $plan = Plan::factory()->create(['is_subscription' => true]);

        $membership = Membership::create([
            'plan_id' => $plan->id,
            'owner_type' => OwnerType::User,
            'owner_id' => 1,
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(30),
        ]);

        $this->assertNotNull($membership->id);
        $this->assertSame($plan->id, $membership->plan_id);
    }
}
