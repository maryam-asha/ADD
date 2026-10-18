<?php

namespace Add\Hr\Tests\Feature\Benefits;

use Add\Hr\Benefits\Models\Benefit;
use Add\Hr\Benefits\Models\BenefitGrant;
use Add\Hr\Benefits\Services\BenefitAssignmentService;
use Add\Hr\Benefits\Services\EmployeeCostReport;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Payroll\Models\PayComponent;
use Add\Hr\Payroll\Models\PayrollRun;
use Add\Hr\Payroll\Models\Payslip;
use Add\Hr\Payroll\Services\PayrollCalculator;
use Add\Hr\Payroll\Services\PayrollRunService;
use Add\Hr\Support\HrException;
use Add\Hr\Tests\Concerns\BuildsPayroll;
use Add\Hr\Tests\Concerns\HiresStaff;
use Add\Hr\Tests\TestCase;
use Carbon\CarbonImmutable;

/**
 * A cash allowance is paid by payroll: granted when the month is
 * calculated, paid once, and fulfilled by the approved payslip.
 */
class BenefitPayrollTest extends TestCase
{
    use BuildsPayroll;
    use HiresStaff;

    private Employee $employee;

    private Benefit $phone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-04-05 09:00:00');
        $this->payStructure();
        $this->lockAttendance(2026, 3);
        $this->lockAttendance(2026, 4);

        $allowances = PayComponent::query()->create([
            'code' => 'allowances', 'name' => ['ar' => 'بدلات'], 'kind' => 'earning', 'calculator' => 'benefit_allowance',
            'applies_to_all' => true, 'sort' => 22,
        ]);
        $this->phone = Benefit::query()->create([
            'code' => 'phone', 'name' => ['ar' => 'بدل هاتف'], 'kind' => 'cash_allowance', 'frequency' => 'monthly',
            'amount' => 50, 'currency' => 'USD', 'pay_component_id' => $allowances->id,
        ])->refresh();

        $this->employee = $this->staff([], ['hired_on' => '2025-03-01']);
        $this->pay($this->employee, ['basic' => 1000]);
        app(BenefitAssignmentService::class)->assign($this->phone, ['employee_id' => $this->employee->id, 'effective_from' => '2026-01-01'], null);
    }

    private function approvedRun(int $month): PayrollRun
    {
        $calculator = $this->user();
        $approver = $this->user();
        $run = app(PayrollRunService::class)->open(['year' => 2026, 'month' => $month, 'kind' => 'regular'], $calculator->id);
        $run = app(PayrollCalculator::class)->calculate($run, $calculator->id);
        $run = app(PayrollRunService::class)->review($run, $calculator->id);

        return app(PayrollRunService::class)->approve($run, $approver);
    }

    public function test_the_allowance_is_paid_once_and_fulfilled_by_the_payslip(): void
    {
        $march = $this->approvedRun(3);

        $slip = Payslip::query()->where('payroll_run_id', $march->id)->with('lines')->sole();
        $this->assertSame('50', $slip->lines->firstWhere('code', 'allowances')->amount);
        $this->assertSame('1050', $slip->gross);

        $grant = BenefitGrant::query()->sole();
        $this->assertSame('2026-03', $grant->period);
        $this->assertSame('fulfilled', $grant->status->value);
        $this->assertSame($slip->id, $grant->payslip_id);

        // April pays April's grant only.
        $april = $this->approvedRun(4);
        $aprilSlip = Payslip::query()->where('payroll_run_id', $april->id)->with('lines')->sole();
        $this->assertCount(1, $aprilSlip->lines->where('code', 'allowances'));
        $this->assertSame(2, BenefitGrant::query()->where('status', 'fulfilled')->count());

        // Its cost is on the payslip, not counted again as a benefit.
        $report = app(EmployeeCostReport::class)->month(CarbonImmutable::parse('2026-03-01'));
        $this->assertSame('0', $report['employees'][0]['costs'][0]['benefits']);
        $this->assertSame($slip->employer_cost, $report['employees'][0]['costs'][0]['payroll']);
    }

    public function test_a_grant_a_cancelled_run_never_paid_is_paid_by_the_next(): void
    {
        $calculator = $this->user();
        $run = app(PayrollRunService::class)->open(['year' => 2026, 'month' => 3, 'kind' => 'regular'], $calculator->id);
        app(PayrollCalculator::class)->calculate($run, $calculator->id);
        app(PayrollRunService::class)->cancel($run);
        $this->assertSame('pending', BenefitGrant::query()->sole()->status->value);

        $april = $this->approvedRun(4);
        $lines = Payslip::query()->where('payroll_run_id', $april->id)->with('lines')->sole()->lines->where('code', 'allowances');
        $this->assertSame(['benefit grant #1', 'benefit grant #2'], $lines->pluck('basis')->values()->all());
        $this->assertSame(0, BenefitGrant::query()->where('status', 'pending')->count());
    }

    public function test_a_grant_paid_by_another_run_meanwhile_blocks_approval_until_recalculated(): void
    {
        $calculator = $this->user();
        $approver = $this->user();
        $runs = app(PayrollRunService::class);
        $payroll = app(PayrollCalculator::class);

        // April is calculated while March is still unapproved, so it carries March's grant too.
        $march = $payroll->calculate($runs->open(['year' => 2026, 'month' => 3, 'kind' => 'regular'], $calculator->id), $calculator->id);
        $april = $payroll->calculate($runs->open(['year' => 2026, 'month' => 4, 'kind' => 'regular'], $calculator->id), $calculator->id);
        $runs->approve($runs->review($march, $calculator->id), $approver);
        $april = $runs->review($april, $calculator->id);

        try {
            $runs->approve($april, $approver);
            $this->fail('April paid March\'s grant a second time.');
        } catch (HrException $e) {
            $this->assertSame(__('hr::payroll.allowance_already_settled'), $e->getMessage());
        }
        $this->assertSame('reviewed', $april->fresh()->status->value);

        $april = $runs->review($payroll->calculate($april->fresh(), $calculator->id), $calculator->id);
        $runs->approve($april, $approver);
        $lines = Payslip::query()->where('payroll_run_id', $april->id)->with('lines')->sole()->lines->where('code', 'allowances');
        $this->assertSame(['benefit grant #2'], $lines->pluck('basis')->values()->all());
    }
}
