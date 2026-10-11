<?php

namespace Tests\Feature\Membership;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\CoverageReason;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Services\PlanHourCoverageService;
use App\Domain\Membership\Services\ResourceQuotaService;
use App\Domain\Membership\Services\SessionCoverageEvaluator;
use Carbon\Carbon;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

class SessionCoverageEvaluatorTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    private SessionCoverageEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc());
        $this->evaluator = app(SessionCoverageEvaluator::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function local(string $time): Carbon
    {
        return Carbon::parse($time, 'Asia/Damascus');
    }

    public function test_the_current_window_end_handles_same_day_and_wrapping_windows(): void
    {
        $day = Plan::factory()->create(['access_window_start' => '08:00', 'access_window_end' => '16:00']);
        $evening = Plan::factory()->evening()->create();
        $overnight = Plan::factory()->create(['access_window_start' => '22:00', 'access_window_end' => '02:00']);

        $this->assertTrue($day->currentWindowEndsAt($this->local('2026-08-17 10:00'))->equalTo($this->local('2026-08-17 16:00')));
        $this->assertNull($day->currentWindowEndsAt($this->local('2026-08-17 16:00')));
        $this->assertTrue($evening->currentWindowEndsAt($this->local('2026-08-17 21:00'))->equalTo($this->local('2026-08-18 00:00')));
        $this->assertNull($evening->currentWindowEndsAt($this->local('2026-08-17 14:00')));
        $this->assertTrue($overnight->currentWindowEndsAt($this->local('2026-08-17 23:00'))->equalTo($this->local('2026-08-18 02:00')));
        $this->assertTrue($overnight->currentWindowEndsAt($this->local('2026-08-18 01:00'))->equalTo($this->local('2026-08-18 02:00')));
        $this->assertNull(Plan::factory()->create()->currentWindowEndsAt($this->local('2026-08-17 10:00')));
    }

    public function test_a_member_who_never_subscribed_has_no_plan(): void
    {
        $coverage = $this->evaluator->evaluate(User::factory()->create(), now());

        $this->assertFalse($coverage->isCovered());
        $this->assertSame(CoverageReason::NoPlan, $coverage->reason);
    }

    public function test_a_lapsed_membership_reads_as_expired(): void
    {
        $member = User::factory()->create();
        $this->subscribe($member, $this->planWithHours(40), now()->subDay());

        $this->assertSame(CoverageReason::PlanExpired, $this->evaluator->evaluate($member, now())->reason);
    }

    public function test_a_plan_without_a_window_or_without_hours_does_not_cover(): void
    {
        $noWindow = User::factory()->create();
        $this->subscribe($noWindow, $this->planWithHours(40, null, null));
        $noHours = User::factory()->create();
        $this->subscribe($noHours, Plan::factory()->create(['access_window_start' => '08:00', 'access_window_end' => '20:00']));

        $this->assertSame(CoverageReason::PlanWithoutHours, $this->evaluator->evaluate($noWindow, now())->reason);
        $this->assertSame(CoverageReason::PlanWithoutHours, $this->evaluator->evaluate($noHours, now())->reason);
    }

    public function test_used_up_hours_do_not_cover(): void
    {
        $member = User::factory()->create();
        $membership = $this->subscribe($member, $this->planWithHours(1));
        app(ResourceQuotaService::class)->consume(app(PlanHourCoverageService::class)->hourGrantFor($membership), $membership, 1.0, $member);

        $coverage = $this->evaluator->evaluate($member, now());

        $this->assertSame(CoverageReason::HoursExhausted, $coverage->reason);
        $this->assertSame(0.0, $coverage->hoursRemaining);
    }

    public function test_outside_the_window_the_remaining_hours_are_still_reported(): void
    {
        $member = User::factory()->create();
        $this->subscribe($member, $this->planWithHours(40, '20:00', '00:00'));

        $coverage = $this->evaluator->evaluate($member, now());

        $this->assertSame(CoverageReason::OutsideWindow, $coverage->reason);
        $this->assertSame(40.0, $coverage->hoursRemaining);
    }

    public function test_covered_until_is_the_window_end_when_the_hours_outlast_it(): void
    {
        $member = User::factory()->create();
        $this->subscribe($member, $this->planWithHours(40));

        $coverage = $this->evaluator->evaluate($member, now());

        $this->assertTrue($coverage->isCovered());
        $this->assertNull($coverage->reason);
        $this->assertTrue($coverage->coveredUntil->equalTo($this->local('2026-08-17 20:00')));
    }

    public function test_covered_until_is_when_the_hours_run_out_when_that_comes_first(): void
    {
        $member = User::factory()->create();
        $this->subscribe($member, $this->planWithHours(2));

        $this->assertTrue($this->evaluator->evaluate($member, now())->coveredUntil->equalTo($this->local('2026-08-17 12:00')));
    }

    public function test_a_company_plan_covers_its_members(): void
    {
        $member = User::factory()->create();
        $company = Company::factory()->create();
        $member->companies()->attach($company->id);
        Membership::create([
            'plan_id' => $this->planWithHours(40)->id,
            'owner_type' => OwnerType::Company,
            'owner_id' => $company->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => now()->subDays(10),
            'current_period_end' => now()->addDays(20),
        ]);

        $this->assertTrue($this->evaluator->evaluate($member, now())->isCovered());
    }

    public function test_the_snapshot_shape(): void
    {
        $member = User::factory()->create();
        $plan = $this->planWithHours(40);
        $this->subscribe($member, $plan);

        $snapshot = $this->evaluator->evaluate($member, now())->toArray();

        $this->assertSame('covered', $snapshot['status']);
        $this->assertNull($snapshot['reason']);
        $this->assertSame($plan->id, $snapshot['plan']['id']);
        $this->assertSame($plan->name, $snapshot['plan']['name']);
        $this->assertSame(40.0, $snapshot['hours_total']);
        $this->assertSame(40.0, $snapshot['hours_remaining']);
        $this->assertSame(['start' => '08:00', 'end' => '20:00'], $snapshot['window']);
        $this->assertSame($this->local('2026-08-17 20:00')->utc()->toIso8601String(), $snapshot['covered_until']);
    }
}
