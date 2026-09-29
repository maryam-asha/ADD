<?php

namespace Tests\Feature\Membership;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\SeatDesk;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A Dedicated Desk subscription is tied to specific desks of its branch,
 * chosen by staff — at activation or later — and no two live subscriptions
 * share a desk (docs/decisions/coworking-seat-pool.md).
 */
class DedicatedDeskAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Space $coSpace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        app()->setLocale('en');

        $this->branch = Branch::factory()->create(['is_default' => true]);
        $this->coSpace = $this->coSpaceIn($this->branch);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
    }

    private function coSpaceIn(Branch $branch): Space
    {
        return Space::factory()->create([
            'building_id' => Building::factory()->create(['branch_id' => $branch->id])->id,
            'space_type' => 'co_space',
        ]);
    }

    private function member(): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Wallet::firstOrCreate(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        return $member;
    }

    private function desk(?Space $space = null): SeatDesk
    {
        return SeatDesk::factory()->create(['space_id' => ($space ?? $this->coSpace)->id]);
    }

    public function test_staff_tie_a_dedicated_desk_to_the_subscription_they_activate(): void
    {
        $plan = Plan::factory()->dedicatedDesk()->create();
        $desk = $this->desk();

        $response = $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => $plan->id,
            'user_id' => $this->member()->id,
            'seat_desk_ids' => [$desk->id],
        ])->assertCreated();

        $response->assertJsonPath('data.desks.0.id', $desk->id)
            ->assertJsonPath('data.desks.0.code', $desk->code);
        $this->assertDatabaseHas('membership_seat_desk', [
            'membership_id' => $response->json('data.id'),
            'seat_desk_id' => $desk->id,
        ]);
    }

    public function test_a_dedicated_subscription_may_start_without_a_desk_and_get_one_later(): void
    {
        $plan = Plan::factory()->dedicatedDesk()->create();
        $desk = $this->desk();

        $id = $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => $plan->id,
            'user_id' => $this->member()->id,
        ])->assertCreated()->json('data.id');

        $this->withHeader('lang', 'en')
            ->putJson("/api/v1/admin/memberships/{$id}/desks", ['seat_desk_ids' => [$desk->id]])
            ->assertOk()
            ->assertExactJson(['message' => 'Desks assigned.']);

        $this->assertSame([$desk->id], Membership::find($id)->seatDesks->pluck('id')->all());
    }

    public function test_only_a_dedicated_desk_plan_takes_desks(): void
    {
        $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => Plan::factory()->create()->id,
            'user_id' => $this->member()->id,
            'seat_desk_ids' => [$this->desk()->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('seat_desk_ids');

        $this->assertDatabaseCount('memberships', 0);
    }

    public function test_a_desk_held_by_another_live_subscription_is_refused(): void
    {
        $plan = Plan::factory()->dedicatedDesk()->create();
        $desk = $this->desk();
        $this->desk(); // a second seat, so the pool is not what refuses
        $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => $plan->id, 'user_id' => $this->member()->id, 'seat_desk_ids' => [$desk->id],
        ])->assertCreated();

        $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => $plan->id, 'user_id' => $this->member()->id, 'seat_desk_ids' => [$desk->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('seat_desk_ids');

        $this->assertDatabaseCount('memberships', 1);
    }

    public function test_a_desk_frees_up_once_its_subscription_has_ended(): void
    {
        $plan = Plan::factory()->dedicatedDesk()->create();
        $desk = $this->desk();
        $old = Membership::factory()->create([
            'plan_id' => $plan->id,
            'branch_id' => $this->branch->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => now()->subDays(40),
            'current_period_end' => now()->subDays(10),
        ]);
        $old->seatDesks()->attach($desk->id);

        $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => $plan->id, 'user_id' => $this->member()->id, 'seat_desk_ids' => [$desk->id],
        ])->assertCreated();
    }

    public function test_a_desk_of_another_branch_is_refused(): void
    {
        $plan = Plan::factory()->dedicatedDesk()->create();
        $elsewhere = $this->desk($this->coSpaceIn(Branch::factory()->create()));

        $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => $plan->id, 'user_id' => $this->member()->id, 'seat_desk_ids' => [$elsewhere->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('seat_desk_ids');
    }

    public function test_no_more_desks_than_the_subscription_holds_seats(): void
    {
        $plan = Plan::factory()->dedicatedDesk()->create();
        $company = Company::factory()->create(['branch_id' => $this->branch->id]);
        Wallet::firstOrCreate(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);

        $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => $plan->id,
            'company_id' => $company->id,
            'seats' => 2,
            'seat_desk_ids' => [$this->desk()->id, $this->desk()->id, $this->desk()->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('seat_desk_ids');

        $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => $plan->id,
            'company_id' => $company->id,
            'seats' => 2,
            'seat_desk_ids' => [$this->desk()->id, $this->desk()->id],
        ])->assertCreated()->assertJsonCount(2, 'data.desks');
    }

    public function test_reassigning_replaces_the_desks(): void
    {
        $plan = Plan::factory()->dedicatedDesk()->create();
        [$first, $second] = [$this->desk(), $this->desk()];
        $id = $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => $plan->id, 'user_id' => $this->member()->id, 'seat_desk_ids' => [$first->id],
        ])->json('data.id');

        // Its own desk is not "held by another" when it is re-sent.
        $this->putJson("/api/v1/admin/memberships/{$id}/desks", ['seat_desk_ids' => [$second->id]])->assertOk();

        $this->assertSame([$second->id], Membership::find($id)->seatDesks->pluck('id')->all());
    }

    public function test_a_desk_tied_to_a_live_subscription_cannot_be_deleted(): void
    {
        $plan = Plan::factory()->dedicatedDesk()->create();
        $desk = $this->desk();
        $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => $plan->id, 'user_id' => $this->member()->id, 'seat_desk_ids' => [$desk->id],
        ])->assertCreated();

        $this->withHeader('lang', 'en')
            ->deleteJson("/api/v1/admin/seats-desks/{$desk->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This desk belongs to a live Dedicated Desk subscription.');

        $this->assertDatabaseHas('seats_desks', ['id' => $desk->id]);
    }

    /** What the dashboard offers when picking a desk: the branch's free ones. */
    public function test_the_desk_list_can_show_only_the_branchs_free_desks(): void
    {
        $plan = Plan::factory()->dedicatedDesk()->create();
        [$taken, $free] = [$this->desk(), $this->desk()];
        $this->desk($this->coSpaceIn(Branch::factory()->create()));
        $membershipId = $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => $plan->id, 'user_id' => $this->member()->id, 'seat_desk_ids' => [$taken->id],
        ])->json('data.id');

        $this->getJson("/api/v1/admin/seats-desks?branch_id={$this->branch->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.membership_id', $membershipId)
            ->assertJsonPath('data.1.membership_id', null);

        $this->getJson("/api/v1/admin/seats-desks?branch_id={$this->branch->id}&free=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $free->id);
    }

    /** Ties a desk to a fresh member's Dedicated Desk subscription; returns the membership id. */
    private function holdDesk(SeatDesk $desk, ?User $member = null): int
    {
        return $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => Plan::factory()->dedicatedDesk()->create()->id,
            'user_id' => ($member ?? $this->member())->id,
            'seat_desk_ids' => [$desk->id],
        ])->assertCreated()->json('data.id');
    }

    /** Deleting the co_space would take its held desks with it, from under their subscriptions. */
    public function test_a_co_space_whose_desks_are_held_cannot_be_deleted(): void
    {
        $this->holdDesk($this->desk());

        $this->withHeader('lang', 'en')
            ->deleteJson("/api/v1/admin/spaces/{$this->coSpace->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Some of this space\'s desks belong to live Dedicated Desk subscriptions.');

        $this->assertDatabaseHas('spaces', ['id' => $this->coSpace->id]);
    }

    /** A held desk stays where its subscription's branch is; moving it is refused until it is untied. */
    public function test_a_held_desk_cannot_move_to_another_space(): void
    {
        $desk = $this->desk();
        $this->holdDesk($desk);
        $elsewhere = $this->coSpaceIn(Branch::factory()->create());

        $this->withHeader('lang', 'en')
            ->putJson("/api/v1/admin/seats-desks/{$desk->id}", ['space_id' => $elsewhere->id, 'code' => $desk->code, 'label' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('space_id');

        // Renaming it in place is fine.
        $this->putJson("/api/v1/admin/seats-desks/{$desk->id}", ['space_id' => $this->coSpace->id, 'code' => $desk->code, 'label' => 'Renamed'])
            ->assertOk();
    }

    /** Its desks would be left in a space that is no longer a co_space. */
    public function test_a_co_space_with_desks_cannot_become_another_type(): void
    {
        $this->desk();

        $this->putJson("/api/v1/admin/spaces/{$this->coSpace->id}", [
            'building_id' => $this->coSpace->building_id,
            'name' => ['ar' => 'غرفة', 'en' => 'Room'],
            'space_type' => 'room',
            'is_lockable' => true,
            'capacity' => 8,
        ])->assertUnprocessable()->assertJsonValidationErrors('space_type');
    }

    /** The member is told which desk is theirs — or that one is still to come. */
    public function test_the_member_sees_their_desk_code(): void
    {
        $member = $this->member();
        $desk = $this->desk();
        $this->holdDesk($desk, $member);

        Sanctum::actingAs($member, ['*']);
        $this->getJson('/api/v1/member/memberships/current')
            ->assertOk()
            ->assertJsonPath('data.desks.0.code', $desk->code)
            ->assertJsonPath('data.desks.0.label', $desk->label);
    }

    public function test_a_dedicated_subscription_without_a_desk_shows_an_empty_list(): void
    {
        $member = $this->member();
        $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => Plan::factory()->dedicatedDesk()->create()->id,
            'user_id' => $member->id,
        ])->assertCreated();

        Sanctum::actingAs($member, ['*']);
        $this->getJson('/api/v1/member/memberships/current')
            ->assertOk()
            ->assertJsonPath('data.desks', []);
    }

    public function test_a_flexi_subscription_has_no_desks_at_all(): void
    {
        $member = $this->member();
        $this->postJson('/api/v1/admin/memberships', ['plan_id' => Plan::factory()->create()->id, 'user_id' => $member->id])
            ->assertCreated();

        Sanctum::actingAs($member, ['*']);
        $this->getJson('/api/v1/member/memberships/current')
            ->assertOk()
            ->assertJsonPath('data.desks', null);
    }

    /**
     * Where staff find the subscriptions a desk is still owed to — one bought
     * in the app arrives without one.
     */
    public function test_staff_list_live_dedicated_subscriptions_and_see_which_lack_a_desk(): void
    {
        $member = $this->member();
        $desk = $this->desk();
        $this->desk();
        $this->desk(); // seats enough for all three sales
        $withDesk = $this->holdDesk($desk, $member);
        $withoutDesk = $this->postJson('/api/v1/admin/memberships', [
            'plan_id' => Plan::factory()->dedicatedDesk()->create()->id,
            'user_id' => $this->member()->id,
        ])->json('data.id');
        $this->postJson('/api/v1/admin/memberships', ['plan_id' => Plan::factory()->create()->id, 'user_id' => $this->member()->id]);
        Membership::factory()->create([
            'plan_id' => Plan::factory()->dedicatedDesk()->create()->id,
            'branch_id' => $this->branch->id,
            'current_period_start' => now()->subDays(40),
            'current_period_end' => now()->subDays(10),
        ]);

        $this->getJson('/api/v1/admin/memberships?dedicated=1&live=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $withoutDesk)
            ->assertJsonPath('data.1.id', $withDesk)
            ->assertJsonPath('data.1.owner.name', $member->name)
            ->assertJsonPath('data.1.desks.0.code', $desk->code)
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/admin/memberships?dedicated=1&live=1&without_desk=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $withoutDesk);
    }
}
