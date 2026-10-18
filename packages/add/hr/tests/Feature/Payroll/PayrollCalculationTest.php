<?php

namespace Add\Hr\Tests\Feature\Payroll;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Leave\Models\LeaveRequest;
use Add\Hr\Leave\Models\LeaveType;
use Add\Hr\Payroll\Models\PayrollRun;
use Add\Hr\Payroll\Models\Payslip;
use Add\Hr\Payroll\Models\RateTableVersion;
use Add\Hr\Payroll\Services\PayrollCalculator;
use Add\Hr\Payroll\Services\PayrollRunService;
use Add\Hr\Support\HrException;
use Add\Hr\Tests\Concerns\BuildsPayroll;
use Add\Hr\Tests\Concerns\HiresStaff;
use Add\Hr\Tests\TestCase;
use Add\Hr\Time\Models\AttendanceDay;
use Add\Hr\Time\Models\OvertimeRequest;

/**
 * March 2026; "now" is 5 April 2026.
 */
class PayrollCalculationTest extends TestCase
{
    use BuildsPayroll;
    use HiresStaff;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-04-05 09:00:00');
        $this->payStructure();
        $this->lockAttendance(2026, 3);

        $this->employee = $this->staff([], ['hired_on' => '2025-03-01']);
        $this->pay($this->employee, ['basic' => 1200, 'transport' => 100]);
    }

    private function calculate(array $data = []): PayrollRun
    {
        $run = app(PayrollRunService::class)->open(['year' => 2026, 'month' => 3, 'kind' => 'regular', ...$data], null);

        return app(PayrollCalculator::class)->calculate($run, 'calculator');
    }

    private function slip(PayrollRun $run, ?Employee $employee = null): Payslip
    {
        return $run->payslips()->where('employee_id', ($employee ?? $this->employee)->id)->with('lines')->sole();
    }

    /**
     * @return array<string, string>
     */
    private function amounts(Payslip $slip): array
    {
        return $slip->lines->mapWithKeys(fn ($line) => [$line->code => $line->amount])->all();
    }

    public function test_a_month_of_pay_with_insurance_and_progressive_tax(): void
    {
        $slip = $this->slip($this->calculate());

        // Employee insurance on 1200 capped at 1000, the employer's uncapped;
        // tax on 1200 - 70 = 1130:
        // 0 + 500 × 10% + 130 × 20% = 76.
        $this->assertSame(['basic' => '1200', 'transport' => '100', 'insurance_ee' => '70', 'insurance_er' => '168', 'tax' => '76'], $this->amounts($slip));
        $this->assertSame('1300', $slip->gross);
        $this->assertSame('146', $slip->deductions);
        $this->assertSame('1154', $slip->net);
        $this->assertSame('1468', $slip->employer_cost);
        $this->assertSame(31, $slip->employed_days);
        $this->assertSame($this->employee->employee_number, $slip->snapshot['employee_number']);
    }

    public function test_a_hire_and_a_raise_mid_month_are_prorated_by_days(): void
    {
        $newcomer = $this->staff([], ['hired_on' => '2026-03-11']);
        $this->pay($newcomer, ['basic' => 1200], '2026-03-11');
        $this->pay($this->employee, ['basic' => 1500, 'transport' => 100], '2026-03-16');

        $run = $this->calculate();

        // 21 of 31 days at 1200.
        $this->assertSame('812.9', $this->amounts($this->slip($run, $newcomer))['basic']);
        $this->assertSame(21, $this->slip($run, $newcomer)->employed_days);

        // 15 days at 1200 + 16 days at 1500.
        $basic = $this->slip($run)->lines->where('code', 'basic')->pluck('amount')->all();
        $this->assertSame(['580.65', '774.19'], $basic);
    }

    public function test_pay_in_another_currency_converts_at_the_runs_rate(): void
    {
        config(['hr.payroll.exchange_rates' => ['SYP:USD' => '0.0001']]);
        $this->pay($this->employee, ['basic' => 1200, 'transport' => 1000000], '2026-03-01', 'SYP');

        $run = $this->calculate();

        $line = $this->slip($run)->lines->firstWhere('code', 'transport');
        $this->assertSame('100', $line->amount);
        $this->assertSame('SYP', $line->original_currency);
        $this->assertSame(['SYP' => '0.0001'], $run->exchange_rates);

        config(['hr.payroll.exchange_rates' => []]);
        $run->update(['status' => 'open']);
        $this->expectException(HrException::class);
        app(PayrollCalculator::class)->calculate($run, null);
    }

    public function test_approved_overtime_and_unpaid_days(): void
    {
        OvertimeRequest::query()->create(['employee_id' => $this->employee->id, 'work_date' => '2026-03-10', 'minutes' => 120, 'kind' => 'post', 'reason' => 'x', 'status' => 'approved']);
        OvertimeRequest::query()->create(['employee_id' => $this->employee->id, 'work_date' => '2026-03-11', 'minutes' => 600, 'kind' => 'post', 'reason' => 'x', 'status' => 'rejected']);
        $unpaid = LeaveType::query()->create(['code' => 'unpaid', 'name' => ['ar' => 'بلا أجر'], 'is_paid' => false, 'tracks_balance' => false]);
        // Monday 9 and Tuesday 10 March: two working days.
        LeaveRequest::query()->create(['employee_id' => $this->employee->id, 'leave_type_id' => $unpaid->id, 'starts_on' => '2026-03-09', 'ends_on' => '2026-03-10', 'amount' => 2, 'status' => 'approved']);
        AttendanceDay::query()->create(['employee_id' => $this->employee->id, 'work_date' => '2026-03-12', 'status' => 'absent']);

        $amounts = $this->amounts($this->slip($this->calculate()));

        // 1200 / 200 h × 2 h × 1.5; 3 days × 1200 / 30.
        $this->assertSame('18', $amounts['overtime']);
        $this->assertSame('120', $amounts['unpaid']);
    }

    public function test_adjustments_are_lines_of_their_own_and_an_off_cycle_run_is_made_of_them(): void
    {
        $runs = app(PayrollRunService::class);
        $run = $runs->open(['year' => 2026, 'month' => 3, 'kind' => 'regular'], null);
        $runs->addAdjustment($run, ['employee_id' => $this->employee->id, 'pay_component_id' => $this->components['bonus']->id, 'amount' => 200, 'currency' => 'USD', 'note' => 'Q1 bonus'], null);
        $slip = $this->slip(app(PayrollCalculator::class)->calculate($run, null));
        $this->assertSame('200', $this->amounts($slip)['bonus']);
        $this->assertSame('Q1 bonus', $slip->lines->firstWhere('code', 'bonus')->basis);

        $offCycle = $runs->open(['year' => 2026, 'month' => 3, 'kind' => 'off_cycle', 'employee_ids' => [$this->employee->id]], null);
        $runs->addAdjustment($offCycle, ['employee_id' => $this->employee->id, 'pay_component_id' => $this->components['bonus']->id, 'amount' => 600, 'currency' => 'USD', 'note' => 'Settlement'], null);
        $slip = $this->slip(app(PayrollCalculator::class)->calculate($offCycle, null));

        // Only the bonus and the tax marked in_off_cycle: 500 × 0 + 100 × 10%.
        $this->assertSame(['bonus' => '600', 'tax' => '10'], $this->amounts($slip));
    }

    public function test_a_regular_run_needs_the_attendance_month_locked(): void
    {
        $run = app(PayrollRunService::class)->open(['year' => 2026, 'month' => 4, 'kind' => 'regular'], null);

        $this->expectException(HrException::class);
        $this->expectExceptionMessage(__('hr::payroll.attendance_not_locked', ['month' => '2026-04']));
        app(PayrollCalculator::class)->calculate($run, null);
    }

    public function test_a_missing_legal_rate_is_a_warning_not_a_guess(): void
    {
        $this->components['tax']->update(['config' => ['table' => 'income_tax', 'base' => 'taxable']]);
        RateTableVersion::query()->update(['effective_from' => '2027-01-01']);

        $slip = $this->slip($this->calculate());

        $this->assertArrayNotHasKey('tax', $this->amounts($slip));
        $this->assertContains('no_rate_in_force:income_tax', $slip->warnings);
    }
}
