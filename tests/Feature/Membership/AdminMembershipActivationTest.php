<?php

namespace Tests\Feature\Membership;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Models\WalletTransaction;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * `POST /api/v1/admin/memberships` — operations activating a plan for a
 * member with no wallet debit (docs/decisions/plan-access-windows.md). This
 * is the only way a private plan reaches anyone.
 */
class AdminMembershipActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        app()->setLocale('en');
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    private function makeMember(): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Wallet::firstOrCreate([
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
        ]);

        return $member;
    }

    public function test_admin_can_activate_a_plan_for_a_member_without_any_wallet_debit(): void
    {
        $this->actingAsAdmin();
        $member = $this->makeMember();
        $plan = Plan::factory()->create(['price_points' => 500, 'duration_days' => 30]);

        $response = $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => $plan->id,
            'user_id' => $member->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.plan_id', $plan->id);

        $this->assertDatabaseHas('memberships', [
            'plan_id' => $plan->id,
            'owner_type' => OwnerType::User->value,
            'owner_id' => $member->id,
        ]);

        $wallet = Wallet::where('owner_type', OwnerType::User)->where('owner_id', $member->id)->first();
        $this->assertSame(0, WalletTransaction::where('wallet_id', $wallet->id)->where('amount', '<', 0)->count());
    }

    public function test_activation_grants_the_plans_balances(): void
    {
        $this->actingAsAdmin();
        $member = $this->makeMember();

        $plan = Plan::factory()->create(['price_points' => 500]);
        $plan->balances()->create([
            'category' => 'space_specific',
            'amount_points' => 400,
            'order' => 0,
        ]);

        $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => $plan->id,
            'user_id' => $member->id,
        ])->assertCreated();

        $wallet = Wallet::where('owner_type', OwnerType::User)->where('owner_id', $member->id)->first();

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'amount' => 400,
        ]);
    }

    public function test_admin_can_activate_a_private_plan_which_is_the_whole_point(): void
    {
        $this->actingAsAdmin();
        $member = $this->makeMember();
        $plan = Plan::factory()->private()->evening()->create();

        $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => $plan->id,
            'user_id' => $member->id,
        ])->assertCreated();

        $this->assertDatabaseHas('memberships', [
            'plan_id' => $plan->id,
            'owner_id' => $member->id,
        ]);
    }

    public function test_activating_a_second_membership_for_the_same_owner_is_rejected(): void
    {
        $this->actingAsAdmin();
        $member = $this->makeMember();
        $plan = Plan::factory()->create();

        $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => $plan->id,
            'user_id' => $member->id,
        ])->assertCreated();

        $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => $plan->id,
            'user_id' => $member->id,
        ])->assertStatus(422);

        $this->assertSame(1, Membership::where('owner_id', $member->id)->count());
    }

    public function test_an_inactive_plan_cannot_be_activated(): void
    {
        $this->actingAsAdmin();
        $member = $this->makeMember();
        $plan = Plan::factory()->create(['is_active' => false]);

        $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => $plan->id,
            'user_id' => $member->id,
        ])->assertStatus(422)->assertJsonValidationErrors('plan_id');
    }

    public function test_user_id_and_company_id_are_mutually_exclusive(): void
    {
        $this->actingAsAdmin();
        $member = $this->makeMember();
        $plan = Plan::factory()->create();

        $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => $plan->id,
            'user_id' => $member->id,
            'company_id' => 1,
        ])->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // Seats in the branch's co-working pool
    // (docs/decisions/coworking-seat-pool.md)
    // ------------------------------------------------------------------

    private function branchWithSeats(int $seats): Branch
    {
        Cache::forget('default_branch_id');
        $branch = Branch::factory()->create(['is_default' => true]);
        Space::factory()->create([
            'building_id' => Building::factory()->create(['branch_id' => $branch->id])->id,
            'capacity' => $seats,
        ]);

        return $branch;
    }

    public function test_a_subscription_is_not_activated_into_a_full_pool(): void
    {
        $this->actingAsAdmin();
        $branch = $this->branchWithSeats(1);
        Membership::factory()->create(['branch_id' => $branch->id]);

        $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => Plan::factory()->create()->id,
            'user_id' => $this->makeMember()->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.membership.seat_pool_full'))
            ->assertJsonStructure(['message', 'first_shortfall_at']);
    }

    /** Staff may sell past the pool by arrangement; the override is on record. */
    public function test_staff_can_override_a_full_pool_and_it_is_audited(): void
    {
        $this->actingAsAdmin();
        $branch = $this->branchWithSeats(1);
        Membership::factory()->create(['branch_id' => $branch->id]);

        $response = $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => Plan::factory()->create()->id,
            'user_id' => $this->makeMember()->id,
            'override_capacity' => true,
        ])->assertCreated();

        $activity = Activity::query()->where('subject_id', $response->json('data.id'))->where('subject_type', Membership::class)->sole();
        $this->assertTrue($activity->properties['capacity_overridden']);
    }

    public function test_a_company_subscription_holds_the_seats_it_was_sold(): void
    {
        $this->actingAsAdmin();
        $branch = $this->branchWithSeats(5);
        $company = Company::factory()->create(['branch_id' => $branch->id]);
        Wallet::firstOrCreate(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);

        $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => Plan::factory()->create()->id,
            'company_id' => $company->id,
            'seats' => 3,
        ])->assertCreated();

        $this->assertSame(3, Membership::where('owner_id', $company->id)->where('owner_type', OwnerType::Company)->sole()->seats);

        // Three held, two left: four more do not fit.
        $other = Company::factory()->create(['branch_id' => $branch->id]);
        Wallet::firstOrCreate(['owner_type' => OwnerType::Company, 'owner_id' => $other->id]);
        $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => Plan::factory()->create()->id,
            'company_id' => $other->id,
            'seats' => 4,
        ])->assertStatus(422);
    }

    public function test_a_members_own_subscription_is_one_seat(): void
    {
        $this->actingAsAdmin();
        $this->branchWithSeats(5);

        $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => Plan::factory()->create()->id,
            'user_id' => $this->makeMember()->id,
            'seats' => 2,
        ])->assertStatus(422)->assertJsonValidationErrors('seats');
    }

    /** A daytime subscription and an evening one share a single seat. */
    public function test_a_plan_whose_hours_do_not_meet_the_held_ones_fits(): void
    {
        $this->actingAsAdmin();
        $branch = $this->branchWithSeats(1);
        Membership::factory()->create([
            'branch_id' => $branch->id,
            'plan_id' => Plan::factory()->create(['access_window_start' => '08:00', 'access_window_end' => '17:00'])->id,
        ]);

        $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => Plan::factory()->evening()->create()->id,
            'user_id' => $this->makeMember()->id,
        ])->assertCreated();
    }

    public function test_a_member_cannot_activate_memberships_for_anyone(): void
    {
        $member = $this->makeMember();
        Sanctum::actingAs($member, ['*']);

        $plan = Plan::factory()->create();

        $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => $plan->id,
            'user_id' => $member->id,
        ])->assertForbidden();
    }

    public function test_operations_cannot_activate_memberships_since_it_is_admin_only(): void
    {
        $operations = User::factory()->create();
        $operations->assignRole('operations');
        Sanctum::actingAs($operations, ['*']);

        $member = $this->makeMember();
        $plan = Plan::factory()->create();

        $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => $plan->id,
            'user_id' => $member->id,
        ])->assertForbidden();
    }
}
