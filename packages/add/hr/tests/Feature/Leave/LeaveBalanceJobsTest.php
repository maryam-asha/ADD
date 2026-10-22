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
}
