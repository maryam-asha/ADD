<?php

namespace Add\Hr\Tests\Feature\Benefits;

use Add\Hr\Authorization\Abilities;
use Add\Hr\Benefits\Models\Benefit;
use Add\Hr\Benefits\Models\BenefitGrant;
use Add\Hr\Benefits\Services\BenefitAssignmentService;
use Add\Hr\Benefits\Services\BenefitGranter;
use Add\Hr\Benefits\Services\BenefitResolver;
use Add\Hr\Contracts\BenefitFulfiller;
use Add\Hr\Contracts\HrNotifier;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Models\JobGrade;
use Add\Hr\Core\Models\OrgUnit;
use Add\Hr\Tests\Concerns\HiresEmployees;
use Add\Hr\Tests\Fixtures\RecordingBenefitFulfiller;
use Add\Hr\Tests\Fixtures\RecordingNotifier;
use Add\Hr\Tests\TestCase;
use Carbon\CarbonImmutable;

/**
 * Who holds a benefit, and granting it month by month: once per period,
 * only to those employed and eligible, delivered through the host and
 * retried after a failure.
 */
class BenefitGrantingTest extends TestCase
{
    use HiresEmployees;

    private RecordingBenefitFulfiller $fulfiller;

    private RecordingNotifier $notifier;

    private OrgUnit $division;

    private OrgUnit $team;

    private JobGrade $grade;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-04-05 09:00:00');
        $this->fulfiller = new RecordingBenefitFulfiller;
        $this->app->instance(BenefitFulfiller::class, $this->fulfiller);
        $this->notifier = new RecordingNotifier;
        $this->app->instance(HrNotifier::class, $this->notifier);

        $this->division = OrgUnit::factory()->create();
        $this->team = OrgUnit::factory()->create(['parent_id' => $this->division->id]);
        $this->grade = JobGrade::factory()->create();
    }

    private function benefit(array $data = []): Benefit
    {
        return Benefit::query()->create([
            'code' => 'b'.uniqid(),
            'name' => ['ar' => 'رصيد مقهى', 'en' => 'Cafe credit'],
            'kind' => 'in_kind_credit',
            'frequency' => 'monthly',
            'amount' => 100,
            'unit' => 'points',
            'employer_cost' => 10,
            'cost_currency' => 'USD',
            ...$data,
        ])->refresh();
    }

    private function assign(Benefit $benefit, array $target, array $extra = []): void
    {
        app(BenefitAssignmentService::class)->assign($benefit, [...$target, 'effective_from' => '2026-01-01', ...$extra], null);
    }

    private function hireInTeam(array $overrides = []): Employee
    {
        return $this->hireEmployee(array_replace_recursive([
            'hired_on' => '2025-01-01',
            'assignment' => ['org_unit_id' => $this->team->id, 'job_grade_id' => $this->grade->id],
        ], $overrides));
    }

    private function grant(string $month): array
    {
        return app(BenefitGranter::class)->run(CarbonImmutable::parse($month));
    }

    public function test_the_most_specific_assignment_decides(): void
    {
        $benefit = $this->benefit();
        $this->assign($benefit, ['org_unit_id' => $this->division->id], ['amount' => 30]);
        $graded = $this->hireInTeam();
        $other = $this->hireInTeam(['assignment' => ['job_grade_id' => JobGrade::factory()->create()->id]]);
        $kept = $this->hireInTeam();
        $resolver = app(BenefitResolver::class);
        $on = CarbonImmutable::parse('2026-04-01');

        // A unit's assignment reaches its sub-units.
        $this->assertSame('30', $resolver->amount($resolver->for($other, $on)->sole()));

        // A grade beats a unit; an employee's own beats both, and may exclude.
        $this->assign($benefit, ['job_grade_id' => $this->grade->id], ['amount' => 50]);
        $this->assign($benefit, ['employee_id' => $kept->id], ['excluded' => true]);
        $this->assertSame('50', $resolver->amount($resolver->for($graded, $on)->sole()));
        $this->assertSame('30', $resolver->amount($resolver->for($other, $on)->sole()));
        $this->assertCount(0, $resolver->for($kept, $on));

        // The nearest unit beats a farther one.
        $this->assign($benefit, ['org_unit_id' => $this->team->id], ['amount' => 40]);
        $this->assertSame('40', $resolver->amount($resolver->for($other, $on)->sole()));
    }

    public function test_an_in_kind_grant_is_delivered_once_per_period(): void
    {
        $benefit = $this->benefit();
        $this->assign($benefit, ['org_unit_id' => $this->division->id]);
        $employee = $this->hireInTeam();

        $this->assertSame(['granted' => 1, 'fulfilled' => 1, 'failed' => 0], $this->grant('2026-04-01'));
        $this->assertSame(['granted' => 0, 'fulfilled' => 0, 'failed' => 0], $this->grant('2026-04-01'));

        $grant = BenefitGrant::query()->sole();
        $this->assertSame('2026-04', $grant->period);
        $this->assertSame('fulfilled', $grant->status->value);
        $this->assertSame('tx-'.$grant->id, $grant->reference);
        $this->assertSame('10', $grant->employer_cost);
        $this->assertSame([$grant->id], $this->fulfiller->delivered);
        $this->assertSame(['benefit_granted'], $this->notifier->keysFor($employee));

        // Changing the benefit later leaves the grant as it was made.
        $benefit->update(['amount' => 500]);
        $this->assertSame('100', $grant->fresh()->amount);
    }

    public function test_a_failed_delivery_is_kept_and_retried(): void
    {
        $this->assign($this->benefit(), ['employee_id' => $this->hireInTeam()->id]);
        $this->fulfiller->failWith = 'No wallet';

        $this->assertSame(['granted' => 1, 'fulfilled' => 0, 'failed' => 1], $this->grant('2026-04-01'));
        $grant = BenefitGrant::query()->sole();
        $this->assertSame('failed', $grant->status->value);
        $this->assertSame('No wallet', $grant->failure);
        $this->assertSame('benefit_grants_failed', $this->notifier->calls[0][1]);

        // A rerun does not retry by itself; HR does once the cause is fixed.
        $this->grant('2026-04-01');
        $this->assertSame(1, $grant->fresh()->attempts);

        $this->fulfiller->failWith = null;
        $retried = app(BenefitGranter::class)->retry($grant);
        $this->assertSame('fulfilled', $retried->status->value);
        $this->assertNull($retried->failure);
        $this->assertSame(2, $retried->attempts);
    }

    public function test_an_in_kind_grant_left_pending_by_a_crash_can_be_retried(): void
    {
        $this->assign($this->benefit(), ['employee_id' => $this->hireInTeam()->id]);
        $this->fulfiller->failWith = 'Database went away';
        $this->grant('2026-04-01');

        // A crash mid-run, rather than a reported failure, leaves it pending.
        $grant = BenefitGrant::query()->sole();
        $grant->update(['status' => 'pending', 'failure' => null]);

        $this->fulfiller->failWith = null;
        $this->assertSame('fulfilled', app(BenefitGranter::class)->retry($grant)->status->value);
    }

    public function test_frequency_eligibility_and_employment_decide_who_is_granted(): void
    {
        $quarterly = $this->benefit(['frequency' => 'quarterly']);
        $yearly = $this->benefit(['frequency' => 'yearly', 'grant_month' => 3]);
        $once = $this->benefit(['frequency' => 'one_time', 'eligible_after_days' => 90]);
        foreach ([$quarterly, $yearly, $once] as $benefit) {
            $this->assign($benefit, ['org_unit_id' => $this->division->id]);
        }

        $veteran = $this->hireInTeam();
        $newcomer = $this->hireInTeam(['hired_on' => '2026-02-15']);
        $leaver = $this->hireInTeam();
        $leaver->update(['terminated_on' => '2026-01-20']);

        $this->grant('2026-02-01');
        $this->assertSame([[$once->id, $veteran->id, 'once']], $this->grants());

        $this->grant('2026-03-01');
        $this->assertContains([$yearly->id, $veteran->id, '2026'], $this->grants());
        $this->assertNotContains([$yearly->id, $leaver->id, '2026'], $this->grants());

        $this->grant('2026-04-01');
        $this->assertContains([$quarterly->id, $veteran->id, '2026-Q2'], $this->grants());
        $this->assertContains([$quarterly->id, $newcomer->id, '2026-Q2'], $this->grants());
        $this->assertNotContains([$once->id, $newcomer->id, 'once'], $this->grants());

        // Ninety days after hire, the one-time benefit comes, once.
        $this->grant('2026-06-01');
        $this->grant('2026-07-01');
        $this->assertSame(1, BenefitGrant::query()->where(['benefit_id' => $once->id, 'employee_id' => $newcomer->id])->count());
        $this->assertSame(0, BenefitGrant::query()->where('employee_id', $leaver->id)->count());
    }

    public function test_eligibility_counts_the_organisations_calendar_days(): void
    {
        // The application runs in UTC and the organisation at +03:00, whose
        // midnight is 21:00 UTC the day before; 10:00 on 5 April locally.
        config(['hr.timezone' => 'Asia/Damascus']);
        $this->travelTo('2026-04-05 07:00:00');
        $benefit = $this->benefit(['eligible_after_days' => 30]);
        $this->assign($benefit, ['org_unit_id' => $this->division->id]);

        // Thirty days after each hire is the 1st of the month granted.
        $april = $this->hireInTeam(['hired_on' => '2026-03-02']);
        $may = $this->hireInTeam(['hired_on' => '2026-04-01']);
        $june = $this->hireInTeam(['hired_on' => '2026-05-02']);

        $this->artisan('hr:benefits-grant')->assertSuccessful();
        $this->artisan('hr:benefits-grant', ['--month' => '2026-05'])->assertSuccessful();
        $this->actingAsWith([Abilities::BENEFITS_MANAGE]);
        $this->postJson('/api/hr/benefit-grants/run', ['month' => '2026-06'])->assertOk();

        $this->assertContains([$benefit->id, $april->id, '2026-04'], $this->grants());
        $this->assertContains([$benefit->id, $may->id, '2026-05'], $this->grants());
        $this->assertContains([$benefit->id, $june->id, '2026-06'], $this->grants());
        $this->assertSame('2026-04-01', BenefitGrant::query()->where('employee_id', $april->id)->orderBy('id')->first()->granted_on->toDateString());
    }

    public function test_an_entitlement_is_recorded_and_the_command_grants_the_current_month(): void
    {
        $this->assign($this->benefit(['kind' => 'entitlement', 'unit' => null, 'amount' => 25, 'currency' => 'USD', 'employer_cost' => 25]), ['job_grade_id' => $this->grade->id]);
        $this->hireInTeam();

        $this->artisan('hr:benefits-grant')->expectsOutputToContain('1 benefits granted')->assertSuccessful();
        $this->artisan('hr:benefits-grant', ['--month' => '2026-13'])->assertFailed();

        $grant = BenefitGrant::query()->sole();
        $this->assertSame('2026-04-01', $grant->granted_on->toDateString());
        $this->assertSame('fulfilled', $grant->status->value);
        $this->assertSame([], $this->fulfiller->delivered);
    }

    /**
     * @return list<array{0: int, 1: int, 2: string}>
     */
    private function grants(): array
    {
        return BenefitGrant::query()->orderBy('id')->get()
            ->map(fn (BenefitGrant $grant) => [$grant->benefit_id, $grant->employee_id, $grant->period])
            ->all();
    }
}
