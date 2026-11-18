<?php

namespace Tests\Feature\Membership;

use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\AllowanceCycle;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\PlanDiscountRedemption;
use App\Domain\Membership\Models\PlanHourUsage;
use App\Domain\Membership\Services\PlanAllowanceService;
use App\Domain\Membership\Services\PlanDiscountService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The hours a person spent and the times they used a discount, in the
 * current cycle, readable by any screen — not only by the checks that
 * refuse once a limit is reached.
 */
class PlanUsageCountersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_spent_seconds_counts_unlimited_time_in_the_cycle(): void
    {
        $member = User::factory()->create();
        $membership = $this->membership(Plan::factory()->create(['allowance_cycle' => AllowanceCycle::Monthly]), OwnerType::User, $member->id, now()->subMonth()->subDays(5));
        [$cycleStart] = app(PlanAllowanceService::class)->cycleFor($membership, now());

        $this->usage($membership, $member, $cycleStart, 3600);
        $this->usage($membership, $member, $cycleStart->addDay(), 3600);
        $this->usage($membership, $member, $cycleStart->subMonthNoOverflow(), 3600);

        $allowance = app(PlanAllowanceService::class)->allowanceFor($membership, SpaceType::CoSpace);

        $this->assertTrue($allowance->isUnlimited());
        $this->assertSame(7200, app(PlanAllowanceService::class)->spentSeconds($membership, $member, $allowance, now()));
    }

    public function test_spent_seconds_is_per_person_on_a_company_plan(): void
    {
        [$first, $second] = User::factory()->count(2)->create();
        $membership = $this->membership(Plan::factory()->coworking(20)->create(), OwnerType::Company, Company::factory()->create()->id, now()->subDays(3));
        $this->usage($membership, $first, $membership->current_period_start, 1800);
        $this->usage($membership, $second, $membership->current_period_start, 1800);

        $service = app(PlanAllowanceService::class);
        $allowance = $service->allowanceFor($membership, SpaceType::CoSpace);

        $this->assertSame(1800, $service->spentSeconds($membership, $first, $allowance, now()));
        $this->assertSame(1800, $service->spentSeconds($membership, $second, $allowance, now()));
    }

    public function test_uses_for_counts_this_users_redemptions_in_the_cycle(): void
    {
        [$member, $other] = User::factory()->count(2)->create();
        $plan = Plan::factory()->create(['allowance_cycle' => AllowanceCycle::Monthly]);
        $discount = $plan->discounts()->create(['percentage' => 30, 'space_type' => SpaceType::EventHall, 'usage_limit' => 6]);
        $membership = $this->membership($plan, OwnerType::User, $member->id, now()->subMonth()->subDays(5));
        [$cycleStart] = app(PlanAllowanceService::class)->cycleFor($membership, now());

        foreach ([[$member, $cycleStart], [$member, $cycleStart->addDay()], [$other, $cycleStart], [$member, $cycleStart->subDay()]] as [$who, $at]) {
            PlanDiscountRedemption::create([
                'plan_discount_id' => $discount->id,
                'membership_id' => $membership->id,
                'user_id' => $who->id,
                'counted_at' => $at,
                'points_discounted' => 10,
            ]);
        }

        $this->assertSame(2, app(PlanDiscountService::class)->usesFor($discount, $membership, $member, now()));
    }

    private function membership(Plan $plan, OwnerType $ownerType, int $ownerId, Carbon $start): Membership
    {
        return Membership::factory()->create([
            'plan_id' => $plan->id,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'status' => MembershipStatus::Active,
            'current_period_start' => $start,
            'current_period_end' => $start->copy()->addDays(90),
        ]);
    }

    private function usage(Membership $membership, User $user, \DateTimeInterface $cycleStart, int $seconds): void
    {
        PlanHourUsage::factory()->create([
            'membership_id' => $membership->id,
            'user_id' => $user->id,
            'allowance_kind' => 'coworking',
            'cycle_start' => $cycleStart,
            'seconds' => $seconds,
        ]);
    }
}
