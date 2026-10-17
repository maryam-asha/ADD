<?php

namespace Add\Hr\Tests\Feature\Payroll;

use Add\Hr\Authorization\Abilities;
use Add\Hr\Contracts\HrNotifier;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Payroll\Models\Loan;
use Add\Hr\Payroll\Models\PayrollRun;
use Add\Hr\Support\Decimal;
use Add\Hr\Tests\Concerns\BuildsPayroll;
use Add\Hr\Tests\Concerns\HiresStaff;
use Add\Hr\Tests\Fixtures\RecordingNotifier;
use Add\Hr\Tests\Fixtures\User;
use Add\Hr\Tests\TestCase;

/**
 * A run through its steps over HTTP, by different people: a payroll officer
 * prepares, a finance manager approves, a cashier pays.
 */
class PayrollRunTest extends TestCase
{
    use BuildsPayroll;
    use HiresStaff;

    private Employee $manager;

    private Employee $employee;

    private User $officer;

    private User $approver;

    private User $cashier;

    private RecordingNotifier $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-04-05 09:00:00');
        $this->notifier = new RecordingNotifier;
        $this->app->instance(HrNotifier::class, $this->notifier);
        $this->payStructure();
        $this->lockAttendance(2026, 3);

        $this->manager = $this->staff([], ['hired_on' => '2025-03-01']);
        $this->employee = $this->reportOf($this->manager, ['hired_on' => '2025-03-01']);
        $this->pay($this->employee, ['basic' => 1200, 'transport' => 100]);
        $this->officer = $this->user([Abilities::PAYROLL_VIEW, Abilities::PAYROLL_MANAGE, Abilities::COMPENSATION_MANAGE]);
        $this->approver = $this->user([Abilities::PAYROLL_VIEW, Abilities::PAYROLL_APPROVE]);
        $this->cashier = $this->user([Abilities::PAYROLL_VIEW, Abilities::PAYROLL_PAY]);
    }

    private function openAndCalculate(array $data = []): int
    {
        $this->actingAs($this->officer);
        $id = $this->postJson('/api/hr/payroll-runs', ['year' => 2026, 'month' => 3, 'kind' => 'regular', ...$data])
            ->assertCreated()->assertJsonPath('data.status', 'open')->json('data.id');
        $this->postJson("/api/hr/payroll-runs/{$id}/calculate")->assertOk();

        return $id;
    }

    public function test_prepare_approve_pay_and_post_by_different_people(): void
    {
        $id = $this->openAndCalculate();
        $this->getJson("/api/hr/payroll-runs/{$id}")->assertOk()
            ->assertJsonPath('data.status', 'calculated')
            ->assertJsonPath('data.totals.payslips', 2)
            ->assertJsonPath('data.totals.net', '1154');
        $this->postJson('/api/hr/payroll-runs', ['year' => 2026, 'month' => 3, 'kind' => 'regular'])
            ->assertUnprocessable()->assertJsonPath('message', __('hr::payroll.run_exists'));

        // Approval waits for review, and is never the calculator's.
        $this->actingAs($this->approver);
        $this->postJson("/api/hr/payroll-runs/{$id}/approve")->assertUnprocessable();
        $this->actingAs($this->officer);
        $this->postJson("/api/hr/payroll-runs/{$id}/review")->assertOk();
        $this->postJson("/api/hr/payroll-runs/{$id}/approve")->assertForbidden();

        $this->actingAs($this->approver);
        $this->postJson("/api/hr/payroll-runs/{$id}/approve")->assertOk();
        $this->assertSame(['payslip_issued'], $this->notifier->keysFor($this->employee));

        // Approved: frozen.
        $this->actingAs($this->officer);
        $this->postJson("/api/hr/payroll-runs/{$id}/calculate")->assertUnprocessable()->assertJsonPath('message', __('hr::payroll.run_locked'));

        $this->actingAs($this->cashier);
        $this->postJson("/api/hr/payroll-runs/{$id}/pay", ['payment_reference' => 'BANK-0326'])->assertOk();
        $journal = $this->getJson("/api/hr/payroll-runs/{$id}/journal")->assertOk()->json('data');
        $this->assertSame(
            Decimal::trim(Decimal::add('0', ...array_column($journal, 'debit'))),
            Decimal::trim(Decimal::add('0', ...array_column($journal, 'credit'))),
        );
        $csv = $this->get("/api/hr/payroll-runs/{$id}/journal?format=csv")->assertOk()->streamedContent();
        $this->assertStringContainsString('payable:net_pay', $csv);
        $this->postJson("/api/hr/payroll-runs/{$id}/post")->assertOk();
        $this->postJson("/api/hr/payroll-runs/{$id}/post")->assertUnprocessable();

        $this->assertSame('paid', PayrollRun::query()->find($id)->status->value);
    }

    public function test_whoever_changed_someones_pay_this_month_cannot_approve(): void
    {
        $this->pay($this->manager, ['basic' => 2000], '2026-03-01', by: $this->approver->id);
        $id = $this->openAndCalculate();
        $this->postJson("/api/hr/payroll-runs/{$id}/review")->assertOk();

        $this->actingAs($this->approver);
        $this->postJson("/api/hr/payroll-runs/{$id}/approve")
            ->assertForbidden()->assertJsonPath('message', __('hr::payroll.sod_compensation'));
    }

    public function test_a_parallel_run_is_confirmed_before_approval(): void
    {
        $id = $this->openAndCalculate(['parallel_run' => true]);
        $this->postJson("/api/hr/payroll-runs/{$id}/review")->assertOk();

        $this->actingAs($this->approver);
        $this->postJson("/api/hr/payroll-runs/{$id}/approve")->assertUnprocessable()->assertJsonPath('message', __('hr::payroll.not_reconciled'));

        $this->actingAs($this->officer);
        $this->postJson("/api/hr/payroll-runs/{$id}/reconcile")->assertOk();
        $this->actingAs($this->approver);
        $this->postJson("/api/hr/payroll-runs/{$id}/approve")->assertOk();
    }

    public function test_payslips_reach_the_employee_only_once_approved(): void
    {
        $id = $this->openAndCalculate();

        $this->actingAsEmployee($this->employee);
        $this->getJson('/api/hr/me/payslips')->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($this->officer);
        $this->postJson("/api/hr/payroll-runs/{$id}/review")->assertOk();
        $this->actingAs($this->approver);
        $this->postJson("/api/hr/payroll-runs/{$id}/approve")->assertOk();

        $this->actingAsEmployee($this->employee);
        $payslip = $this->getJson('/api/hr/me/payslips')->assertOk()
            ->assertJsonPath('data.0.period', '2026-03')
            ->assertJsonPath('data.0.net', '1154')
            ->assertJsonMissingPath('data.0.employer_cost')
            ->json('data.0.id');
        $this->getJson("/api/hr/me/payslips/{$payslip}")->assertOk()->assertJsonCount(5, 'data.lines');

        $this->actingAsEmployee($this->manager);
        $this->getJson("/api/hr/me/payslips/{$payslip}")->assertNotFound();
    }

    public function test_the_variance_report_flags_a_big_change_against_last_month(): void
    {
        $id = $this->openAndCalculate();
        $this->postJson("/api/hr/payroll-runs/{$id}/review")->assertOk();
        $this->actingAs($this->approver);
        $this->postJson("/api/hr/payroll-runs/{$id}/approve")->assertOk();

        $this->travelTo('2026-05-05 09:00:00');
        $this->lockAttendance(2026, 4);
        $this->pay($this->employee, ['basic' => 1800, 'transport' => 100], '2026-04-01');
        $april = $this->openAndCalculate(['month' => 4]);

        $rows = collect($this->getJson("/api/hr/payroll-runs/{$april}/variance")->assertOk()->json('data.rows'))->keyBy('employee_id');
        $this->assertTrue($rows[$this->employee->id]['flagged']);
        $this->assertSame(['code' => 'basic', 'previous' => '1200', 'current' => '1800'], $rows[$this->employee->id]['components'][0]);
    }

    public function test_a_loan_is_approved_deducted_month_by_month_and_settled(): void
    {
        $this->actingAsEmployee($this->employee);
        $this->postJson('/api/hr/me/loans', ['amount' => 5000, 'currency' => 'USD', 'installments' => 10])
            ->assertUnprocessable()->assertJsonPath('message', __('hr::payroll.loan_over_cap', ['max' => '2400', 'currency' => 'USD']));
        $approval = $this->postJson('/api/hr/me/loans', ['amount' => 1000, 'currency' => 'USD', 'installments' => 3, 'first_month' => '2026-03'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.approval_request_id');
        $this->postJson('/api/hr/me/loans', ['amount' => 100, 'currency' => 'USD', 'installments' => 1])
            ->assertUnprocessable()->assertJsonPath('message', __('hr::payroll.loan_open'));

        $this->actingAsEmployee($this->manager);
        $this->postJson("/api/hr/me/approvals/{$approval}/approve")->assertOk();

        $this->actingAsEmployee($this->employee);
        $this->getJson('/api/hr/me/loans')->assertOk()
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.0.schedule.0.amount', '333.33')
            ->assertJsonPath('data.0.schedule.2.amount', '333.34')
            ->assertJsonPath('data.0.outstanding', '1000');

        $id = $this->openAndCalculate();
        $this->assertSame('333.33', PayrollRun::query()->find($id)->payslips()->where('employee_id', $this->employee->id)->sole()->lines()->where('code', 'loan')->value('amount'));
        $this->postJson("/api/hr/payroll-runs/{$id}/review")->assertOk();
        $this->actingAs($this->approver);
        $this->postJson("/api/hr/payroll-runs/{$id}/approve")->assertOk();

        $loan = Loan::query()->sole();
        $this->assertSame(['deducted', 'due', 'due'], $loan->schedule->map(fn ($i) => $i->status->value)->all());

        $this->actingAs($this->user([Abilities::LOANS_MANAGE]));
        $this->postJson("/api/hr/loans/{$loan->id}/settle", ['note' => 'Repaid in cash'])->assertOk();
        $this->assertSame('settled', $loan->refresh()->status->value);
    }

    public function test_pay_changes_are_effective_dated_and_on_the_timeline(): void
    {
        $this->actingAs($this->officer);
        $url = "/api/hr/employees/{$this->employee->id}/compensation";

        $this->putJson($url, ['effective_from' => '2026-05-01', 'reason' => 'raise', 'lines' => [
            ['pay_component_id' => $this->components['basic']->id, 'amount' => 1400, 'currency' => 'usd'],
        ]])->assertOk();
        $this->putJson($url, ['effective_from' => '2026-04-01', 'lines' => []])
            ->assertUnprocessable()->assertJsonPath('message', __('hr::payroll.compensation_date_invalid'));

        $this->getJson($url)->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.effective_to', '2026-04-30')
            ->assertJsonPath('data.2.amount', '1400')
            ->assertJsonPath('data.2.currency', 'USD');
        $this->assertTrue($this->employee->events()->where('type', 'compensation_change')->where('reason', 'raise')->exists());
    }

    public function test_rate_table_versions_never_overlap(): void
    {
        $this->actingAsWith([Abilities::SETTINGS_MANAGE, Abilities::PAYROLL_VIEW]);
        $tax = collect($this->getJson('/api/hr/rate-tables')->assertOk()->json('data'))->firstWhere('code', 'income_tax')['id'];

        $this->postJson("/api/hr/rate-tables/{$tax}/versions", ['effective_from' => '2027-01-01', 'brackets' => [['up_to' => null, 'rate' => 15]]])
            ->assertUnprocessable()->assertJsonPath('message', __('hr::payroll.rate_version_overlap'));
        $this->postJson("/api/hr/rate-tables/{$tax}/versions", ['effective_from' => '2027-01-01', 'rate' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors(['rate', 'brackets']);

        $version = collect($this->getJson("/api/hr/rate-tables/{$tax}")->json('data.versions'))->first()['id'];
        $this->patchJson("/api/hr/rate-table-versions/{$version}", ['effective_to' => '2026-12-31'])->assertOk();
        $this->postJson("/api/hr/rate-tables/{$tax}/versions", ['effective_from' => '2027-01-01', 'brackets' => [['up_to' => null, 'rate' => 15]]])
            ->assertCreated()->assertJsonCount(2, 'data.versions');
    }

    public function test_end_of_service_and_certificates(): void
    {
        $this->actingAs($this->user([Abilities::PAYROLL_VIEW]));

        // 366 days / 365 × 30 days × 1200 / 30.
        $this->getJson("/api/hr/employees/{$this->employee->id}/end-of-service?as_of=2026-03-01")->assertOk()
            ->assertJsonPath('data.days', '30.08')
            ->assertJsonPath('data.amount', '1203.29')
            ->assertJsonPath('data.currency', 'USD');
        $this->getJson('/api/hr/reports/end-of-service-liability?as_of=2026-03-01')->assertOk()
            ->assertJsonCount(2, 'data.employees');

        $this->actingAsEmployee($this->employee);
        $this->getJson('/api/hr/me/certificates/employment')->assertOk()->assertJsonPath('data.hired_on', '2025-03-01');
        $this->getJson('/api/hr/me/certificates/salary')->assertOk()
            ->assertJsonCount(2, 'data.monthly_pay')
            ->assertJsonPath('data.last_payslip', null);
    }

    public function test_each_step_needs_its_ability(): void
    {
        $this->actingAs($this->approver);
        $this->postJson('/api/hr/payroll-runs', ['year' => 2026, 'month' => 3, 'kind' => 'regular'])->assertForbidden();
        $this->putJson("/api/hr/employees/{$this->employee->id}/compensation", ['effective_from' => '2026-05-01', 'lines' => []])->assertForbidden();

        $this->actingAs($this->officer);
        $this->postJson('/api/hr/pay-components', ['code' => 'x', 'name' => ['ar' => 'س'], 'kind' => 'earning', 'calculator' => 'fixed'])->assertForbidden();
        $this->getJson('/api/hr/loans')->assertForbidden();
    }
}
