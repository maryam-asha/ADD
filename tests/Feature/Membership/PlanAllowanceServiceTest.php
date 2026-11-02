<?php

namespace Tests\Feature\Membership;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\AllowanceCycle;
use App\Domain\Membership\Enums\CoworkingSeat;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Services\PlanAllowanceService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * How much of a stretch a person's plan covers, per person and per cycle,
 * from the plan's own coworking terms (product owner, 2026-11-02).
 */
class PlanAllowanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private PlanAllowanceService $allowances;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00', 'Asia/Damascus')->utc());
        $this->allowances = app(PlanAllowanceService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function local(string $at): CarbonImmutable
    {
        return CarbonImmutable::parse($at, 'Asia/Damascus')->utc();
    }

    private function membership(Plan $plan, ?User $owner = null, ?Company $company = null, string $start = '2026-08-07 00:00'): Membership
    {
        return Membership::create([
            'plan_id' => $plan->id,
            'owner_type' => $company ? OwnerType::Company : OwnerType::User,
            'owner_id' => $company?->id ?? ($owner ?? User::factory()->create())->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => $this->local($start),
            'current_period_end' => $this->local($start)->addDays(90),
        ]);
    }

    private function coworkingSpace(): Space
    {
        return Space::factory()->create(['space_type' => SpaceType::CoSpace]);
    }

    /** Spend $seconds of $member's coworking hours on a walk-in at $at. */
    private function spend(Membership $m, User $member, int $seconds, string $at = '2026-08-17 09:00'): void
    {
        $allowance = $this->allowances->allowanceFor($m, SpaceType::CoSpace);
        $session = WalkinSession::factory()->create(['user_id' => $member->id]);
        $this->allowances->record($m, $member, $allowance, $session, $seconds, $this->local($at));
    }

    private function cover(Membership $m, User $member, Space $space, string $from, string $to): int
    {
        return $this->allowances->coverableSeconds($m, $member, $space, $this->local($from), $this->local($to));
    }

    public function test_unlimited_flexible_plan_covers_the_whole_stretch(): void
    {
        $member = User::factory()->create();
        $m = $this->membership(Plan::factory()->coworking()->create(['access_window_start' => '08:00', 'access_window_end' => '20:00']), $member);

        $this->assertSame(3 * 3600, $this->cover($m, $member, $this->coworkingSpace(), '2026-08-17 10:00', '2026-08-17 13:00'));
        $this->assertNull($this->allowances->remainingSeconds($m, $member, $this->allowances->allowanceFor($m, SpaceType::CoSpace), now()));
    }

    public function test_all_day_plan_without_window_covers_any_hour(): void
    {
        $member = User::factory()->create();
        $m = $this->membership(Plan::factory()->coworking(20)->create(), $member);

        $this->assertSame(2 * 3600, $this->cover($m, $member, $this->coworkingSpace(), '2026-08-17 23:00', '2026-08-18 01:00'));
    }

    public function test_window_plan_covers_only_inside_its_window(): void
    {
        $member = User::factory()->create();
        $m = $this->membership(Plan::factory()->coworking()->create(['access_window_start' => '09:00', 'access_window_end' => '17:00']), $member);

        $this->assertSame(3600, $this->cover($m, $member, $this->coworkingSpace(), '2026-08-17 16:00', '2026-08-17 19:00'));
    }

    public function test_limited_plan_is_capped_by_what_is_left(): void
    {
        $member = User::factory()->create();
        $m = $this->membership(Plan::factory()->coworking(20)->create(), $member);
        $this->spend($m, $member, 19 * 3600);

        $this->assertSame(3600, $this->cover($m, $member, $this->coworkingSpace(), '2026-08-17 10:00', '2026-08-17 13:00'));
    }

    public function test_monthly_cycle_resets_on_the_subscription_day(): void
    {
        $member = User::factory()->create();
        $plan = Plan::factory()->coworking(20)->create(['allowance_cycle' => AllowanceCycle::Monthly]);
        $m = $this->membership($plan, $member, start: '2026-07-15 00:00');
        $this->spend($m, $member, 20 * 3600, '2026-07-20 10:00');
        $allowance = $this->allowances->allowanceFor($m, SpaceType::CoSpace);

        $this->assertSame(0, $this->allowances->remainingSeconds($m, $member, $allowance, $this->local('2026-08-14 10:00')));
        $this->assertSame(20 * 3600, $this->allowances->remainingSeconds($m, $member, $allowance, $this->local('2026-08-16 10:00')));
        [$start, $end] = $this->allowances->cycleFor($m, $this->local('2026-08-16 10:00'));
        $this->assertTrue($start->equalTo($this->local('2026-08-15 00:00')));
        $this->assertTrue($end->equalTo($this->local('2026-09-15 00:00')));
    }

    public function test_usage_counts_against_the_cycle_it_started_in(): void
    {
        $member = User::factory()->create();
        $plan = Plan::factory()->coworking(20)->create(['allowance_cycle' => AllowanceCycle::Monthly]);
        $m = $this->membership($plan, $member, start: '2026-07-15 00:00');
        // Started in July's cycle, ran past midnight into August's.
        $this->spend($m, $member, 5 * 3600, '2026-08-14 22:00');
        $allowance = $this->allowances->allowanceFor($m, SpaceType::CoSpace);

        $this->assertSame(15 * 3600, $this->allowances->remainingSeconds($m, $member, $allowance, $this->local('2026-08-14 23:00')));
        $this->assertSame(20 * 3600, $this->allowances->remainingSeconds($m, $member, $allowance, $this->local('2026-08-15 08:00')));
    }

    public function test_company_hours_are_per_employee(): void
    {
        $company = Company::factory()->create();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $m = $this->membership(Plan::factory()->coworking(10)->create(), company: $company);
        $this->spend($m, $a, 10 * 3600);
        $allowance = $this->allowances->allowanceFor($m, SpaceType::CoSpace);

        $this->assertSame(0, $this->allowances->remainingSeconds($m, $a, $allowance, now()));
        $this->assertSame(10 * 3600, $this->allowances->remainingSeconds($m, $b, $allowance, now()));
    }

    public function test_a_non_coworking_plan_with_three_coworking_hours_covers_three_hours_any_time(): void
    {
        $member = User::factory()->create();
        $plan = Plan::factory()->notCoworking()->create();
        $plan->spaceHours()->create(['space_type' => SpaceType::CoSpace, 'hours' => 3]);
        $m = $this->membership($plan, $member);

        $allowance = $this->allowances->allowanceFor($m, SpaceType::CoSpace);
        $this->assertNotNull($allowance);
        $this->assertFalse($allowance->windowed);
        $this->assertSame(3 * 3600, $this->cover($m, $member, $this->coworkingSpace(), '2026-08-17 21:00', '2026-08-18 02:00'));
    }

    public function test_a_room_benefit_matches_the_space_before_the_type(): void
    {
        $member = User::factory()->create();
        $room = Space::factory()->room()->create();
        $plan = Plan::factory()->notCoworking()->create();
        $plan->spaceHours()->create(['space_type' => SpaceType::Room, 'hours' => 2]);
        $plan->spaceHours()->create(['space_id' => $room->id, 'hours' => 5]);
        $m = $this->membership($plan, $member);

        $this->assertSame(5.0, $this->allowances->allowanceFor($m, SpaceType::Room, $room->id)->hours);
        $this->assertSame(2.0, $this->allowances->allowanceFor($m, SpaceType::Room)->hours);
    }

    public function test_a_non_coworking_plan_without_a_coworking_benefit_covers_nothing(): void
    {
        $member = User::factory()->create();
        $m = $this->membership(Plan::factory()->notCoworking()->create(['access_window_start' => '08:00', 'access_window_end' => '20:00']), $member);

        $this->assertNull($this->allowances->allowanceFor($m, SpaceType::CoSpace));
        $this->assertSame(0, $this->cover($m, $member, $this->coworkingSpace(), '2026-08-17 10:00', '2026-08-17 12:00'));
    }

    public function test_dedicated_plan_is_unlimited_even_if_hours_were_set(): void
    {
        $member = User::factory()->create();
        $m = $this->membership(Plan::factory()->create(['coworking_seat' => CoworkingSeat::Dedicated, 'coworking_hours' => 5]), $member);

        $this->assertTrue($this->allowances->allowanceFor($m, SpaceType::CoSpace)->isUnlimited());
    }

    public function test_recording_more_than_is_left_is_refused(): void
    {
        $member = User::factory()->create();
        $m = $this->membership(Plan::factory()->coworking(1)->create(), $member);

        $this->expectException(ReceptionActionException::class);
        $this->spend($m, $member, 2 * 3600);
    }

    public function test_release_returns_the_seconds(): void
    {
        $member = User::factory()->create();
        $m = $this->membership(Plan::factory()->coworking(10)->create(), $member);
        $allowance = $this->allowances->allowanceFor($m, SpaceType::CoSpace);
        $session = WalkinSession::factory()->create(['user_id' => $member->id]);
        $this->allowances->record($m, $member, $allowance, $session, 4 * 3600, now());

        $this->assertSame(4 * 3600, $this->allowances->releaseFor($session));
        $this->assertSame(10 * 3600, $this->allowances->remainingSeconds($m, $member, $allowance, now()));
    }
}
