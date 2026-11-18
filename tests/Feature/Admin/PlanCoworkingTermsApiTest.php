<?php

namespace Tests\Feature\Admin;

use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\CoworkingSeat;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\PlanHourUsage;
use App\Domain\Membership\Models\ResourceType;
use App\Domain\Membership\Services\PlanAllowanceService;
use App\Domain\Membership\Services\PlanCreationService;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The plan API takes the coworking seat, hours, cycle and per-space hours,
 * one benefit per space (product owner, 2026-11-02).
 */
class PlanCoworkingTermsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, ResourceTypeSeeder::class]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => ['en' => 'Flex Desk', 'ar' => 'مكتب مرن'],
            'is_subscription' => true,
            'price_points' => 500,
            'duration_days' => 90,
            'coworking_seat' => 'flexible',
            'coworking_hours' => 20,
            'allowance_cycle' => 'monthly',
            'access_window_start' => '09:00',
            'access_window_end' => '17:00',
        ], $overrides);
    }

    public function test_a_flexible_monthly_plan_is_created_with_its_terms_inside_data(): void
    {
        $this->postJson('/api/v1/admin/plans', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.coworking_seat', 'flexible')
            ->assertJsonPath('data.coworking_hours', '20')
            ->assertJsonPath('data.allowance_cycle', 'monthly')
            ->assertJsonPath('data.space_hours', []);
    }

    public function test_unlimited_is_no_hours(): void
    {
        $this->postJson('/api/v1/admin/plans', $this->payload(['coworking_hours' => null]))
            ->assertCreated()
            ->assertJsonPath('data.coworking_hours', null);
    }

    public function test_hours_on_a_dedicated_plan_are_refused(): void
    {
        $this->postJson('/api/v1/admin/plans', $this->payload(['coworking_seat' => 'dedicated']))
            ->assertStatus(422)->assertJsonValidationErrors('coworking_hours');
    }

    public function test_a_window_on_a_non_coworking_plan_is_refused(): void
    {
        $this->postJson('/api/v1/admin/plans', $this->payload(['coworking_seat' => 'none', 'coworking_hours' => null]))
            ->assertStatus(422)->assertJsonValidationErrors(['access_window_start', 'access_window_end']);
    }

    public function test_the_same_space_type_in_discounts_and_space_hours_is_refused(): void
    {
        $this->postJson('/api/v1/admin/plans', $this->payload([
            'discounts' => [['percentage' => 20, 'space_type' => 'room', 'is_active' => true]],
            'space_hours' => [['space_type' => 'room', 'hours' => 5]],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['space_hours.0.space_type' => __('api.admin.plan_benefit_duplicate_target')]);
    }

    public function test_a_coworking_plan_cannot_take_a_coworking_benefit(): void
    {
        $this->postJson('/api/v1/admin/plans', $this->payload([
            'discounts' => [['percentage' => 10, 'space_type' => 'co_space', 'is_active' => true]],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['discounts.0.space_type' => __('api.admin.plan_coworking_benefit_on_coworking_plan')]);
    }

    public function test_a_coworking_plan_cannot_target_a_coworking_space_by_id(): void
    {
        $desk = Space::factory()->create(['space_type' => SpaceType::CoSpace]);

        $this->postJson('/api/v1/admin/plans', $this->payload([
            'space_hours' => [['space_id' => $desk->id, 'hours' => 3]],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('space_hours.0.space_id');
    }

    public function test_a_non_coworking_plan_may_give_three_coworking_hours(): void
    {
        $this->postJson('/api/v1/admin/plans', $this->payload([
            'coworking_seat' => 'none',
            'coworking_hours' => null,
            'access_window_start' => null,
            'access_window_end' => null,
            'space_hours' => [['space_type' => 'co_space', 'hours' => 3]],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.coworking_seat', 'none')
            ->assertJsonPath('data.space_hours.0.space_type', 'co_space')
            ->assertJsonPath('data.space_hours.0.hours', '3');
    }

    public function test_the_space_hours_grant_is_no_longer_offered_beside_explicit_terms(): void
    {
        $this->postJson('/api/v1/admin/plans', $this->payload([
            'resource_grants' => [['resource_type_id' => ResourceType::where('slug', 'space-hours')->value('id'), 'quantity' => 20]],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('resource_grants.0.resource_type_id');
    }

    /** A provider's design still sends the old grant: it becomes the plan's coworking hours. */
    public function test_a_legacy_space_hours_grant_without_terms_becomes_coworking_hours(): void
    {
        $payload = $this->payload([
            'resource_grants' => [['resource_type_id' => ResourceType::where('slug', 'space-hours')->value('id'), 'quantity' => 25]],
        ]);
        unset($payload['coworking_seat'], $payload['coworking_hours'], $payload['allowance_cycle']);

        $id = $this->postJson('/api/v1/admin/plans', $payload)->assertCreated()->json('data.id');

        $plan = Plan::query()->findOrFail($id);
        $this->assertSame(CoworkingSeat::Flexible, $plan->coworking_seat);
        $this->assertSame(25.0, (float) $plan->coworking_hours);
        $this->assertSame(0, $plan->resourceGrants()->count());
    }

    /** Review I6: a design that states no terms (a provider's) keeps holding seats, as every plan did before. */
    public function test_a_design_with_no_terms_and_no_hours_grant_is_an_unlimited_flexible_plan(): void
    {
        $payload = $this->payload();
        unset($payload['coworking_seat'], $payload['coworking_hours'], $payload['allowance_cycle']);

        $plan = app(PlanCreationService::class)->create($payload);

        $this->assertSame(CoworkingSeat::Flexible, $plan->refresh()->coworking_seat);
        $this->assertNull($plan->coworking_hours);
    }

    public function test_update_replaces_and_clears_space_hours(): void
    {
        $id = $this->postJson('/api/v1/admin/plans', $this->payload([
            'space_hours' => [['space_type' => 'room', 'hours' => 5]],
        ]))->assertCreated()->json('data.id');

        $this->putJson("/api/v1/admin/plans/{$id}", $this->payload(['space_hours' => [['space_type' => 'business', 'hours' => 2]]]))->assertOk();
        $this->assertSame([SpaceType::Business], Plan::find($id)->spaceHours->pluck('space_type')->all());

        $this->putJson("/api/v1/admin/plans/{$id}", $this->payload(['space_hours' => []]))->assertOk();
        $this->assertSame(0, Plan::find($id)->spaceHours()->count());
    }

    /** Review C1: saving a plan must not turn free-hours use into coworking-hours use. */
    public function test_saving_a_plan_keeps_hours_spent_on_its_benefits_apart_from_coworking(): void
    {
        $id = $this->postJson('/api/v1/admin/plans', $this->payload([
            'coworking_hours' => 20,
            'space_hours' => [['space_type' => 'room', 'hours' => 5]],
        ]))->assertCreated()->json('data.id');
        $plan = Plan::query()->findOrFail($id);
        $member = User::factory()->create();
        $membership = Membership::factory()->create(['plan_id' => $plan->id, 'owner_id' => $member->id, 'current_period_start' => now()->subDay()]);
        $allowances = app(PlanAllowanceService::class);
        $rooms = $allowances->allowanceFor($membership, SpaceType::Room);
        $allowances->record($membership, $member, $rooms, WalkinSession::factory()->create(['user_id' => $member->id]), 5 * 3600, now());

        $row = $plan->spaceHours()->sole();
        $this->putJson("/api/v1/admin/plans/{$id}", $this->payload([
            'price_points' => 600,
            'space_hours' => [['id' => $row->id, 'space_type' => 'room', 'hours' => 5]],
        ]))->assertOk();

        $this->assertSame($row->id, $plan->spaceHours()->sole()->id);
        $this->assertSame(0, $allowances->remainingSeconds($membership->refresh(), $member, $allowances->allowanceFor($membership, SpaceType::Room), now()));
        $this->assertSame(20 * 3600, $allowances->remainingSeconds($membership, $member, $allowances->allowanceFor($membership, SpaceType::CoSpace), now()));

        // Removing the benefit leaves coworking untouched too.
        $this->putJson("/api/v1/admin/plans/{$id}", $this->payload(['space_hours' => []]))->assertOk();
        $this->assertSame(20 * 3600, $allowances->remainingSeconds($membership->refresh(), $member, $allowances->allowanceFor($membership, SpaceType::CoSpace), now()));
    }

    public function test_switching_a_plan_off_coworking_keeps_its_usage_history(): void
    {
        $plan = Plan::factory()->coworking(20)->create();
        $membership = Membership::factory()->create(['plan_id' => $plan->id]);
        PlanHourUsage::factory()->create([
            'membership_id' => $membership->id,
            'usable_type' => (new WalkinSession)->getMorphClass(),
            'usable_id' => WalkinSession::factory()->create()->id,
        ]);

        $this->putJson("/api/v1/admin/plans/{$plan->id}", $this->payload([
            'coworking_seat' => 'none',
            'coworking_hours' => null,
            'access_window_start' => null,
            'access_window_end' => null,
        ]))->assertOk();

        $this->assertSame(CoworkingSeat::None, $plan->refresh()->coworking_seat);
        $this->assertSame(1, PlanHourUsage::count());
    }
}
