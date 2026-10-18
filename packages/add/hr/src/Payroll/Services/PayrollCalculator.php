<?php

namespace Add\Hr\Payroll\Services;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Contracts\ExchangeRateProvider;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Payroll\Calculation\CalculatorRegistry;
use Add\Hr\Payroll\Calculation\LineResult;
use Add\Hr\Payroll\Calculation\PayrollContext;
use Add\Hr\Payroll\Calculation\RunRates;
use Add\Hr\Payroll\Enums\PayComponentKind;
use Add\Hr\Payroll\Enums\PayrollRunKind;
use Add\Hr\Payroll\Enums\PayrollRunStatus;
use Add\Hr\Payroll\Models\Compensation;
use Add\Hr\Payroll\Models\PayComponent;
use Add\Hr\Payroll\Models\PayrollAdjustment;
use Add\Hr\Payroll\Models\PayrollRun;
use Add\Hr\Payroll\Models\Payslip;
use Add\Hr\Support\Decimal;
use Add\Hr\Support\HrException;
use Add\Hr\Time\Services\PeriodGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Computes every payslip of a run from scratch, as often as needed until it
 * is approved. A regular run reads only a locked attendance month and
 * covers everyone employed in it; an off-cycle run covers the employees it
 * names and is made of its adjustments (plus components marked
 * config.in_off_cycle, such as the tax on a bonus).
 *
 * For each employee, active components run in `sort` order; a component
 * applies when it applies_to_all or the employee has pay on it this month,
 * and the run's adjustments for it are added as lines of their own.
 */
class PayrollCalculator
{
    public function __construct(
        private readonly CalculatorRegistry $calculators,
        private readonly ExchangeRateProvider $provider,
        private readonly PeriodGuard $periods,
    ) {}

    public function calculate(PayrollRun $run, int|string|null $actorId): PayrollRun
    {
        return DB::transaction(function () use ($run, $actorId) {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);

            if (! in_array($run->status, [PayrollRunStatus::Open, PayrollRunStatus::Calculated, PayrollRunStatus::Reviewed], true)) {
                HrException::fail('hr::payroll.run_locked');
            }

            if ($run->kind === PayrollRunKind::Regular
                && config('hr.payroll.require_locked_attendance', true)
                && ! $this->periods->isLocked($run->monthStart())) {
                HrException::fail('hr::payroll.attendance_not_locked', ['month' => $run->monthStart()->format('Y-m')]);
            }

            $rates = new RunRates($run->currency, $this->provider);
            $components = PayComponent::query()->where('is_active', true)->orderBy('sort')->orderBy('id')->get();
            $run->payslips()->delete();

            foreach ($this->employees($run) as $employee) {
                $this->payslip($run, $employee, $components, $rates);
            }

            $run->update([
                'status' => PayrollRunStatus::Calculated,
                'exchange_rates' => $rates->all() ?: null,
                'calculated_at' => now(),
                'calculated_by' => $actorId,
                'reviewed_at' => null,
                'reviewed_by' => null,
                'reconciled_at' => null,
                'reconciled_by' => null,
            ]);

            HrAudit::record('payroll_run_calculated', $run, ['payslips' => $run->payslips()->count()]);

            return $run->refresh();
        });
    }

    /**
     * @return Collection<int, Employee>
     */
    private function employees(PayrollRun $run): Collection
    {
        if ($run->kind === PayrollRunKind::OffCycle) {
            return Employee::query()->whereIn('id', (array) $run->employee_ids)->orderBy('id')->get();
        }

        return Employee::query()
            ->where('hired_on', '<=', $run->monthEnd()->toDateString())
            ->where(fn ($q) => $q->whereNull('terminated_on')->orWhere('terminated_on', '>=', $run->monthStart()->toDateString()))
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, PayComponent>  $components
     */
    private function payslip(PayrollRun $run, Employee $employee, Collection $components, RunRates $rates): Payslip
    {
        $from = $run->kind === PayrollRunKind::Regular ? $employee->hired_on->max($run->monthStart()) : $run->monthStart();
        $to = $run->kind === PayrollRunKind::Regular && $employee->terminated_on !== null ? $employee->terminated_on->min($run->monthEnd()) : $run->monthEnd();
        $daysInMonth = $run->monthStart()->daysInMonth;
        $context = new PayrollContext($run, $employee, $from, $to, (int) $from->diffInDays($to) + 1, $daysInMonth, $rates);
        $adjustments = PayrollAdjustment::query()->where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->get()->groupBy('pay_component_id');

        foreach ($components as $component) {
            if ($this->applies($run, $component, $context)) {
                array_push($context->lines, ...$this->calculators->get($component->calculator)->calculate($component, $context));
            }

            foreach ($adjustments->get($component->id, collect()) as $adjustment) {
                $context->lines[] = new LineResult($component, $context->money($context->convert($adjustment->amount, $adjustment->currency)),
                    basis: $adjustment->note, originalAmount: $adjustment->amount, originalCurrency: $adjustment->currency);
            }
        }

        return $this->store($run, $employee, $context);
    }

    private function applies(PayrollRun $run, PayComponent $component, PayrollContext $context): bool
    {
        if ($run->kind === PayrollRunKind::OffCycle) {
            return $component->applies_to_all && (bool) $component->setting('in_off_cycle', false);
        }

        return $component->applies_to_all || Compensation::query()
            ->where('employee_id', $context->employee->id)
            ->where('pay_component_id', $component->id)
            ->overlapping($context->from, $context->to)
            ->exists();
    }

    private function store(PayrollRun $run, Employee $employee, PayrollContext $context): Payslip
    {
        $sum = fn (PayComponentKind $kind) => Decimal::add('0', ...array_map(
            fn (LineResult $line) => $line->amount,
            array_filter($context->lines, fn (LineResult $line) => $line->component->kind === $kind),
        ));

        $gross = $sum(PayComponentKind::Earning);
        $deductions = $sum(PayComponentKind::Deduction);
        $net = Decimal::sub($gross, $deductions);

        if (Decimal::cmp($net, '0') < 0) {
            $context->warnings[] = 'negative_net';
        }

        $assignment = $employee->currentAssignment($context->to);

        $payslip = $run->payslips()->create([
            'employee_id' => $employee->id,
            'currency' => $run->currency,
            'gross' => $gross,
            'deductions' => $deductions,
            'net' => $net,
            'employer_cost' => Decimal::add($gross, $sum(PayComponentKind::EmployerContribution), $sum(PayComponentKind::InKind)),
            'employed_days' => $context->employedDays,
            'days_in_month' => $context->daysInMonth,
            'snapshot' => [
                'employee_number' => $employee->employee_number,
                'name' => $employee->name,
                'job_title' => $assignment?->resolvedTitle(),
                'org_unit' => $assignment?->orgUnit?->name,
                'hired_on' => $employee->hired_on->toDateString(),
                'terminated_on' => $employee->terminated_on?->toDateString(),
            ],
            'warnings' => $context->warnings ?: null,
        ]);

        foreach (array_values($context->lines) as $index => $line) {
            $payslip->lines()->create([
                'pay_component_id' => $line->component->id,
                'code' => $line->component->code,
                'name' => $line->component->name,
                'kind' => $line->component->kind,
                'basis' => $line->basis === null ? null : mb_substr($line->basis, 0, 255),
                'quantity' => $line->quantity,
                'rate' => $line->rate,
                'amount' => $line->amount,
                'original_amount' => $line->originalAmount,
                'original_currency' => $line->originalCurrency,
                'taxable' => $line->component->taxable,
                'insurable' => $line->component->insurable,
                'sort' => $index,
            ]);
        }

        return $payslip;
    }
}
