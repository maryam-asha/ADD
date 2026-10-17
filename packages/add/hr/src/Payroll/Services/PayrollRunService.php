<?php

namespace Add\Hr\Payroll\Services;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Contracts\HrNotifier;
use Add\Hr\Payroll\Enums\InstallmentStatus;
use Add\Hr\Payroll\Enums\LoanStatus;
use Add\Hr\Payroll\Enums\PayrollRunKind;
use Add\Hr\Payroll\Enums\PayrollRunStatus;
use Add\Hr\Payroll\Events\PayrollRunApproved;
use Add\Hr\Payroll\Models\Compensation;
use Add\Hr\Payroll\Models\LoanInstallment;
use Add\Hr\Payroll\Models\PayrollAdjustment;
use Add\Hr\Payroll\Models\PayrollRun;
use Add\Hr\Support\HrException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * A run's life: open → calculated → reviewed → approved → paid, posted to
 * accounting once. Before approval anything may be recalculated; an
 * approved run never changes — a correction is an off-cycle run.
 *
 * Separation of duties at approval: not the person who calculated the run,
 * nor anyone who changed the pay of one of its employees for that month.
 */
class PayrollRunService
{
    public function __construct(private readonly HrNotifier $notifier) {}

    /**
     * @param  array{year: int, month: int, kind: string, currency?: ?string, parallel_run?: bool, employee_ids?: ?list<int>, notes?: ?string}  $data
     */
    public function open(array $data, int|string|null $actorId): PayrollRun
    {
        $kind = PayrollRunKind::from($data['kind']);

        if ($kind === PayrollRunKind::Regular && PayrollRun::query()
            ->where(['year' => $data['year'], 'month' => $data['month'], 'kind' => $kind->value])->exists()) {
            HrException::fail('hr::payroll.run_exists');
        }

        if ($kind === PayrollRunKind::OffCycle && empty($data['employee_ids'])) {
            HrException::fail('hr::payroll.off_cycle_needs_employees');
        }

        $run = PayrollRun::query()->create([
            'year' => $data['year'],
            'month' => $data['month'],
            'kind' => $kind,
            'currency' => strtoupper($data['currency'] ?? (string) config('hr.payroll.currency', 'USD')),
            'status' => PayrollRunStatus::Open,
            'parallel_run' => (bool) ($data['parallel_run'] ?? false),
            'employee_ids' => $kind === PayrollRunKind::OffCycle ? array_values(array_map('intval', $data['employee_ids'])) : null,
            'notes' => $data['notes'] ?? null,
            'opened_by' => $actorId,
        ]);

        HrAudit::record('payroll_run_opened', $run);

        return $run;
    }

    /**
     * An adjustment sends a calculated run back to open: it needs a new
     * calculation before review.
     *
     * @param  array{employee_id: int, pay_component_id: int, amount: string|float|int, currency: string, note: string}  $data
     */
    public function addAdjustment(PayrollRun $run, array $data, int|string|null $actorId): PayrollAdjustment
    {
        return DB::transaction(function () use ($run, $data, $actorId) {
            $run = $this->editable($run);

            if ($run->kind === PayrollRunKind::OffCycle && ! in_array((int) $data['employee_id'], (array) $run->employee_ids, true)) {
                HrException::fail('hr::payroll.employee_not_in_run');
            }

            $adjustment = $run->adjustments()->create([...$data, 'currency' => strtoupper($data['currency']), 'created_by' => $actorId]);
            $run->update(['status' => PayrollRunStatus::Open]);
            HrAudit::record('payroll_adjustment_added', $run, ['adjustment_id' => $adjustment->id]);

            return $adjustment;
        });
    }

    public function removeAdjustment(PayrollAdjustment $adjustment): void
    {
        DB::transaction(function () use ($adjustment) {
            $run = $this->editable(PayrollRun::query()->findOrFail($adjustment->payroll_run_id));
            $adjustment->delete();
            $run->update(['status' => PayrollRunStatus::Open]);
        });
    }

    public function review(PayrollRun $run, int|string|null $actorId): PayrollRun
    {
        return $this->transition($run, PayrollRunStatus::Calculated, function (PayrollRun $run) use ($actorId) {
            $run->update(['status' => PayrollRunStatus::Reviewed, 'reviewed_at' => now(), 'reviewed_by' => $actorId]);
        });
    }

    /**
     * For a parallel run: someone confirms it matches the payroll it runs
     * beside, before it may be approved.
     */
    public function confirmReconciliation(PayrollRun $run, int|string|null $actorId): PayrollRun
    {
        return DB::transaction(function () use ($run, $actorId) {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);

            if (! $run->parallel_run || ! in_array($run->status, [PayrollRunStatus::Calculated, PayrollRunStatus::Reviewed], true)) {
                HrException::fail('hr::payroll.not_reconcilable');
            }

            $run->update(['reconciled_at' => now(), 'reconciled_by' => $actorId]);
            HrAudit::record('payroll_run_reconciled', $run);

            return $run;
        });
    }

    public function approve(PayrollRun $run, Authenticatable $user): PayrollRun
    {
        $userId = $user->getAuthIdentifier();

        $approved = $this->transition($run, PayrollRunStatus::Reviewed, function (PayrollRun $run) use ($userId) {
            if ($run->parallel_run && $run->reconciled_at === null) {
                HrException::fail('hr::payroll.not_reconciled');
            }

            if ((string) $run->calculated_by === (string) $userId) {
                HrException::fail('hr::payroll.sod_calculated', [], 403);
            }

            $employeeIds = $run->payslips()->pluck('employee_id');

            if (Compensation::query()->whereIn('employee_id', $employeeIds)->where('created_by', $userId)
                ->overlapping($run->monthStart(), $run->monthEnd())->exists()) {
                HrException::fail('hr::payroll.sod_compensation', [], 403);
            }

            $run->update(['status' => PayrollRunStatus::Approved, 'approved_at' => now(), 'approved_by' => $userId]);
            $run->payslips()->update(['published_at' => now()]);
            $this->deductInstallments($run);
            PayrollRunApproved::dispatch($run);
        });

        $payslips = $approved->payslips()->with('employee')->get();
        DB::afterCommit(fn () => $payslips->each(fn ($payslip) => $this->notifier->notifyEmployee($payslip->employee, 'payslip_issued', [
            'month' => $approved->monthStart()->format('Y-m'),
        ])));

        return $approved;
    }

    public function pay(PayrollRun $run, ?string $reference, int|string|null $actorId): PayrollRun
    {
        return $this->transition($run, PayrollRunStatus::Approved, function (PayrollRun $run) use ($reference, $actorId) {
            $run->update(['status' => PayrollRunStatus::Paid, 'paid_at' => now(), 'paid_by' => $actorId, 'payment_reference' => $reference]);
        });
    }

    public function markPosted(PayrollRun $run): PayrollRun
    {
        return DB::transaction(function () use ($run) {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);

            if (! $run->isLocked() || $run->posted_at !== null) {
                HrException::fail('hr::payroll.not_postable');
            }

            $run->update(['posted_at' => now()]);
            HrAudit::record('payroll_run_posted', $run);

            return $run;
        });
    }

    /**
     * A run not yet approved may be thrown away, slips and all.
     */
    public function cancel(PayrollRun $run): void
    {
        DB::transaction(function () use ($run) {
            $run = $this->editable($run);
            HrAudit::record('payroll_run_cancelled', $run);
            $run->delete();
        });
    }

    /**
     * Installments due this month for the run's employees are taken by
     * their payslips; a loan with nothing left due is settled.
     */
    private function deductInstallments(PayrollRun $run): void
    {
        foreach ($run->payslips()->get() as $payslip) {
            if (! $payslip->lines()->where('basis', 'like', 'loan #%')->exists()) {
                continue;
            }

            $installments = LoanInstallment::query()
                ->where(['year' => $run->year, 'month' => $run->month, 'status' => InstallmentStatus::Due->value])
                ->whereHas('loan', fn ($q) => $q->where('employee_id', $payslip->employee_id)->where('status', LoanStatus::Active->value))
                ->get();

            foreach ($installments as $installment) {
                $installment->update(['status' => InstallmentStatus::Deducted, 'payslip_id' => $payslip->id]);

                if (! $installment->loan->schedule()->where('status', InstallmentStatus::Due->value)->exists()) {
                    $installment->loan->update(['status' => LoanStatus::Settled]);
                }
            }
        }
    }

    private function editable(PayrollRun $run): PayrollRun
    {
        $run = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);

        if ($run->isLocked()) {
            HrException::fail('hr::payroll.run_locked');
        }

        return $run;
    }

    private function transition(PayrollRun $run, PayrollRunStatus $from, callable $change): PayrollRun
    {
        return DB::transaction(function () use ($run, $from, $change) {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($run->status !== $from) {
                HrException::fail('hr::payroll.wrong_status', ['status' => $from->value]);
            }

            $change($run);
            HrAudit::record('payroll_run_'.$run->status->value, $run);

            return $run->refresh();
        });
    }
}
