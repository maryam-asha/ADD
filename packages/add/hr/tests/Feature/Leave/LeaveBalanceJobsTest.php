<?php

namespace Add\Hr\Tests\Feature\Leave;

use Add\Hr\Core\Models\JobGrade;
use Add\Hr\Leave\Enums\LeaveRequestStatus;
use Add\Hr\Leave\Enums\LedgerKind;
use Add\Hr\Leave\Models\LeaveRequest;
use Add\Hr\Leave\Models\LedgerEntry;
use Add\Hr\Leave\Services\LeaveLedger;
use Add\Hr\Leave\Services\LeavePolicyResolver;
use Add\Hr\Tests\Concerns\BuildsLeave;
use Add\Hr\Tests\Concerns\HiresEmployees;
use Add\Hr\Tests\Concerns\TravelsOnTheOrganisationsClock;
use Add\Hr\Tests\TestCase;
use Carbon\CarbonImmutable;

class LeaveBalanceJobsTest extends TestCase
{
    use BuildsLeave;
    use HiresEmployees;
    use TravelsOnTheOrganisationsClock;

    private function credits(int $employeeId): array
    {
        return LedgerEntry::query()->where('employee_id', $employeeId)->orderBy('id')
            ->get()->map(fn ($entry) => [$entry->reference, $entry->amount])->all();
    }

    public function test_monthly_accrual_prorates_the_hire_month_and_never_books_twice(): void
    {
        $this->travelTo('2026-03-05');
        $type = $this->leaveType();
        $this->policy($type, ['accrual_method' => 'monthly', 'annual_entitlement' => 24, 'effective_from' => '2026-01-01']);
        $employee = $this->hireEmployee(['hired_on' => '2026-01-16']);

        $this->artisan('hr:leave-accrue')->assertSuccessful();
        $this->artisan('hr:leave-accrue')->assertSuccessful();

        // 16 of January's 31 days, then all of February; March has not ended.
        $this->assertSame([['accrual:2026-01', '1.03'], ['accrual:2026-02', '2']], $this->credits($employee->id));
    }

    public function test_a_policy_never_credits_days_before_it_was_in_force(): void
    {
        $this->travelTo('2026-03-05');
        $type = $this->leaveType();
        $this->policy($type, ['accrual_method' => 'monthly', 'annual_entitlement' => 24, 'effective_from' => '2026-02-01']);
        $employee = $this->hireEmployee(['hired_on' => '2025-06-01']);

        $this->artisan('hr:leave-accrue')->assertSuccessful();

        $this->assertSame([['accrual:2026-02', '2']], $this->credits($employee->id));
    }

    public function test_annual_grant_is_prorated_in_the_hire_year_in_halves(): void
    {
        $this->travelTo('2026-07-10');
        $type = $this->leaveType();
        $this->policy($type, ['accrual_method' => 'annual_grant', 'annual_entitlement' => 21]);
        $employee = $this->hireEmployee(['hired_on' => '2026-07-01']);

        $this->artisan('hr:leave-accrue')->assertSuccessful();

        // 184 of 365 days left: 10.59, rounded to the half day.
        $this->assertSame([['grant:2026', '10.5']], $this->credits($employee->id));
    }

    public function test_seniority_tiers_raise_the_entitlement(): void
    {
        $this->travelTo('2026-01-05');
        $type = $this->leaveType();
        $this->policy($type, [
            'accrual_method' => 'annual_grant',
            'annual_entitlement' => 21,
            'service_tiers' => [['min_years' => 5, 'entitlement' => 30], ['min_years' => 10, 'entitlement' => 35]],
        ]);
        $employee = $this->hireEmployee(['hired_on' => '2020-03-01']);

        $this->artisan('hr:leave-accrue')->assertSuccessful();

        $this->assertSame([['grant:2025', '21'], ['grant:2026', '30']], $this->credits($employee->id));
    }

    public function test_the_most_specific_policy_applies(): void
    {
        $grade = JobGrade::factory()->create();
        $type = $this->leaveType();
        $this->policy($type, ['annual_entitlement' => 21]);
        $senior = $this->policy($type, ['annual_entitlement' => 30, 'job_grade_ids' => [$grade->id]]);
        $this->policy($type, ['annual_entitlement' => 25, 'contract_types' => ['open_ended']]);

        $employee = $this->hireEmployee(['assignment' => ['job_grade_id' => $grade->id]]);

        $this->assertSame($senior->id, app(LeavePolicyResolver::class)->for($employee, $type, CarbonImmutable::today())->id);
    }

    public function test_year_end_caps_the_carry_over_then_expires_unused_carried_days(): void
    {
        $this->travelTo('2026-01-10');
        $type = $this->leaveType();
        $this->policy($type, ['carry_over_max' => 5, 'carry_over_expiry_months' => 3]);
        $employee = $this->hireEmployee(['hired_on' => '2024-01-01']);
        $this->giveBalance($employee, $type, 15, '2025-01-01');
        $this->giveBalance($employee, $type, 21, '2026-01-01');

        $this->artisan('hr:leave-year-end')->assertSuccessful();
        $this->artisan('hr:leave-year-end')->assertSuccessful();
        $this->assertSame(26.0, $this->balanceOf($employee, $type));

        // Three days taken: they come out of the carried 2025 days first.
        $request = LeaveRequest::query()->create([
            'employee_id' => $employee->id, 'leave_type_id' => $type->id,
            'starts_on' => '2026-01-12', 'ends_on' => '2026-01-14', 'amount' => 3,
            'status' => LeaveRequestStatus::Approved,
        ]);
        app(LeaveLedger::class)->consume($request);
        $this->assertSame([2025 => 2.0, 2026 => 21.0], app(LeaveLedger::class)->buckets($employee, $type));

        $this->travelTo('2026-03-31');
        $this->artisan('hr:leave-year-end')->assertSuccessful();
        $this->assertSame(23.0, $this->balanceOf($employee, $type));

        $this->travelTo('2026-04-01');
        $this->artisan('hr:leave-year-end')->assertSuccessful();
        $this->artisan('hr:leave-year-end')->assertSuccessful();
        $this->assertSame(21.0, $this->balanceOf($employee, $type));
        $this->assertSame(2, LedgerEntry::query()->where('kind', LedgerKind::Expiry->value)->count());
    }

    public function test_a_cancelled_leave_gives_days_back_to_the_years_it_took_them_from(): void
    {
        $this->travelTo('2026-01-10');
        $type = $this->leaveType();
        $employee = $this->hireEmployee(['hired_on' => '2024-01-01']);
        $this->giveBalance($employee, $type, 2, '2025-06-01');
        $this->giveBalance($employee, $type, 21, '2026-01-01');
        $request = LeaveRequest::query()->create([
            'employee_id' => $employee->id, 'leave_type_id' => $type->id,
            'starts_on' => '2026-02-02', 'ends_on' => '2026-02-06', 'amount' => 5,
            'status' => LeaveRequestStatus::Approved,
        ]);
        $ledger = app(LeaveLedger::class);

        $ledger->consume($request);
        $this->assertSame([2025 => 0.0, 2026 => 18.0], $ledger->buckets($employee, $type));

        $ledger->reverse($request);
        $this->assertSame([2025 => 2.0, 2026 => 21.0], $ledger->buckets($employee, $type));
    }

    public function test_the_ledger_reads_and_books_on_the_organisations_day(): void
    {
        $this->travelToLocal('2026-12-15 12:00');
        $type = $this->leaveType();
        $employee = $this->hireEmployee(['hired_on' => '2024-01-01']);
        $this->giveBalance($employee, $type, 21, '2027-01-01');
        $request = LeaveRequest::query()->create([
            'employee_id' => $employee->id, 'leave_type_id' => $type->id,
            'starts_on' => '2027-01-04', 'ends_on' => '2027-01-05', 'amount' => 2,
            'status' => LeaveRequestStatus::Approved,
        ]);

        // 01:00 on 1 January locally, still 31 December in UTC.
        $this->travelToLocal('2027-01-01 01:00');
        $this->assertSame(21.0, $this->balanceOf($employee, $type));

        app(LeaveLedger::class)->consume($request);
        $usage = LedgerEntry::query()->where('kind', LedgerKind::Usage->value)->sole();
        $this->assertSame(2027, $usage->period_year);
        $this->assertSame('2027-01-01', $usage->effective_on->toDateString());
    }

    public function test_the_daily_jobs_run_on_the_organisations_day(): void
    {
        $this->travelToLocal('2026-12-15 12:00');
        $type = $this->leaveType();
        $this->policy($type, ['accrual_method' => 'annual_grant', 'annual_entitlement' => 21, 'carry_over_max' => 5]);
        $employee = $this->hireEmployee(['hired_on' => '2024-01-01']);
        $this->giveBalance($employee, $type, 15, '2026-01-01');

        // 01:00 on 1 January locally, still 31 December in UTC.
        $this->travelToLocal('2027-01-01 01:00');
        $this->artisan('hr:leave-accrue')->assertSuccessful();
        $this->artisan('hr:leave-year-end')->assertSuccessful();

        $this->assertContains(['grant:2027', '21'], $this->credits($employee->id));
        $this->assertTrue(LedgerEntry::query()->where('employee_id', $employee->id)->where('kind', LedgerKind::Expiry->value)->exists());
    }

    public function test_a_hire_anniversary_policy_renews_each_employee_on_their_own_day(): void
    {
        $this->travelTo('2026-08-10');
        $type = $this->leaveType();
        $this->policy($type, ['accrual_method' => 'annual_grant', 'annual_entitlement' => 21, 'grant_cycle' => 'hire_anniversary']);
        $employee = $this->hireEmployee(['hired_on' => '2024-06-15']);

        $this->artisan('hr:leave-accrue')->assertSuccessful();

        $this->assertSame([['grant:a2025', '21'], ['grant:a2026', '21']], $this->credits($employee->id));
        $this->assertSame(['2025-06-15', '2026-06-15'], LedgerEntry::query()->where('employee_id', $employee->id)->orderBy('id')->get()
            ->map(fn ($entry) => $entry->effective_on->toDateString())->all());
    }

    public function test_with_no_carry_over_the_anniversary_year_lapses_the_day_before_the_next(): void
    {
        $type = $this->leaveType();
        $this->policy($type, ['accrual_method' => 'annual_grant', 'annual_entitlement' => 21, 'grant_cycle' => 'hire_anniversary', 'carry_over_mode' => 'none']);
        $this->travelTo('2026-06-14');
        $employee = $this->hireEmployee(['hired_on' => '2024-06-15']);

        $this->artisan('hr:leave-accrue')->assertSuccessful();
        $this->artisan('hr:leave-year-end')->assertSuccessful();
        $this->assertSame(21.0, $this->balanceOf($employee, $type));

        $this->travelTo('2026-06-15');
        $this->artisan('hr:leave-accrue')->assertSuccessful();
        $this->artisan('hr:leave-year-end')->assertSuccessful();
        $this->artisan('hr:leave-year-end')->assertSuccessful();

        // Last year's 21 lapsed on 14 June; this year's 21 are what is left.
        $this->assertSame(21.0, $this->balanceOf($employee, $type));
        $this->assertSame([2025 => 0.0, 2026 => 21.0], app(LeaveLedger::class)->buckets($employee, $type));
    }

    public function test_carry_over_is_none_capped_or_unlimited_as_the_policy_says(): void
    {
        $this->travelTo('2026-01-10');
        $none = $this->leaveType();
        $capped = $this->leaveType();
        $unlimited = $this->leaveType();
        $this->policy($none, ['carry_over_mode' => 'none']);
        $this->policy($capped, ['carry_over_mode' => 'capped', 'carry_over_max' => 5]);
        $this->policy($unlimited, ['carry_over_mode' => 'unlimited', 'carry_over_max' => 5]);
        $employee = $this->hireEmployee(['hired_on' => '2024-01-01']);
        foreach ([$none, $capped, $unlimited] as $type) {
            $this->giveBalance($employee, $type, 15, '2025-01-01');
        }

        $this->artisan('hr:leave-year-end')->assertSuccessful();

        $this->assertSame(0.0, $this->balanceOf($employee, $none));
        $this->assertSame(5.0, $this->balanceOf($employee, $capped));
        // A limit left on the policy does not apply once every day carries over.
        $this->assertSame(15.0, $this->balanceOf($employee, $unlimited));
    }

    public function test_a_new_hire_gets_a_share_the_whole_year_or_nothing_until_probation_is_over(): void
    {
        $this->travelTo('2026-07-10');
        $full = $this->leaveType();
        $afterProbation = $this->leaveType();
        $this->policy($full, ['accrual_method' => 'annual_grant', 'annual_entitlement' => 21, 'hire_grant' => 'full']);
        $this->policy($afterProbation, ['accrual_method' => 'annual_grant', 'annual_entitlement' => 21, 'hire_grant' => 'after_probation']);
        $employee = $this->hireEmployee(['hired_on' => '2026-07-01', 'contract' => ['probation_ends_on' => '2026-09-30']]);

        $this->artisan('hr:leave-accrue')->assertSuccessful();
        $this->assertSame(21.0, $this->balanceOf($employee, $full));
        $this->assertSame(0.0, $this->balanceOf($employee, $afterProbation));

        $this->travelTo('2026-10-01');
        $this->artisan('hr:leave-accrue')->assertSuccessful();
        $this->artisan('hr:leave-accrue')->assertSuccessful();

        // From 1 October: 92 of 365 days, 5.29, rounded to the half day.
        $this->assertSame(5.5, $this->balanceOf($employee, $afterProbation));
        $this->assertSame(21.0, $this->balanceOf($employee, $full));
    }

    public function test_a_tier_reached_on_the_service_anniversary_pays_the_difference_for_the_rest_of_the_year(): void
    {
        $this->travelTo('2026-03-10');
        $onAnniversary = $this->leaveType();
        $nextYear = $this->leaveType();
        $tiers = [['min_years' => 5, 'entitlement' => 35]];
        $this->policy($onAnniversary, ['accrual_method' => 'annual_grant', 'annual_entitlement' => 21, 'service_tiers' => $tiers, 'tier_timing' => 'on_anniversary']);
        $this->policy($nextYear, ['accrual_method' => 'annual_grant', 'annual_entitlement' => 21, 'service_tiers' => $tiers]);
        $employee = $this->hireEmployee(['hired_on' => '2021-03-01']);

        $this->artisan('hr:leave-accrue')->assertSuccessful();
        $this->artisan('hr:leave-accrue')->assertSuccessful();

        // Five years on 1 March 2026: 14 more days for 306 of 365 days, 11.74, to the half day.
        $this->assertSame(21.0 + 21.0 + 11.5, $this->balanceOf($employee, $onAnniversary));
        $this->assertSame(21.0 + 21.0, $this->balanceOf($employee, $nextYear));
    }

    public function test_a_new_hire_sees_the_first_grant_at_once_not_the_next_night(): void
    {
        $this->travelTo('2026-07-10');
        $type = $this->leaveType();
        $this->policy($type, ['accrual_method' => 'annual_grant', 'annual_entitlement' => 21]);

        $employee = $this->hireEmployee(['hired_on' => '2026-07-01']);

        $this->assertSame([['grant:2026', '10.5']], $this->credits($employee->id));
        $this->artisan('hr:leave-accrue')->assertSuccessful();
        $this->assertSame([['grant:2026', '10.5']], $this->credits($employee->id));
    }
}
