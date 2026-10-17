<?php

namespace Add\Hr\Benefits\Services;

use Add\Hr\Benefits\Enums\BenefitGrantStatus;
use Add\Hr\Benefits\Models\BenefitGrant;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Payroll\Enums\PayrollRunStatus;
use Add\Hr\Payroll\Models\Payslip;
use Add\Hr\Support\Decimal;
use Carbon\CarbonImmutable;

/**
 * What each employee cost in a month: the employer cost of their approved
 * payslips (pay, employer contributions, cash allowances) plus the cost of
 * the other benefits granted that month (in kind, entitlements). Money in
 * different currencies is never added together.
 */
class EmployeeCostReport
{
    /**
     * @return array{month: string, totals: array<string, array<string, string>>, employees: list<array<string, mixed>>}
     */
    public function month(CarbonImmutable $month): array
    {
        $month = $month->startOfMonth();
        $costs = [];

        $payslips = Payslip::query()
            ->whereHas('run', fn ($q) => $q->where('year', $month->year)->where('month', $month->month)
                ->whereIn('status', [PayrollRunStatus::Approved->value, PayrollRunStatus::Paid->value]))
            ->get(['employee_id', 'currency', 'employer_cost']);

        foreach ($payslips as $payslip) {
            $this->add($costs, $payslip->employee_id, $payslip->currency, 'payroll', $payslip->employer_cost);
        }

        $grants = BenefitGrant::query()
            ->whereBetween('granted_on', [$month->toDateString(), $month->endOfMonth()->toDateString()])
            ->where('status', '!=', BenefitGrantStatus::Cancelled->value)
            ->whereNotNull('employer_cost')
            ->get(['employee_id', 'cost_currency', 'employer_cost']);

        foreach ($grants as $grant) {
            $this->add($costs, $grant->employee_id, (string) $grant->cost_currency, 'benefits', $grant->employer_cost);
        }

        $employees = Employee::query()->whereIn('id', array_keys($costs))->orderBy('id')->get()->keyBy('id');
        $totals = [];
        $rows = [];

        foreach ($employees as $id => $employee) {
            $lines = [];

            foreach ($costs[$id] as $currency => $parts) {
                $line = ['currency' => $currency, 'payroll' => $parts['payroll'] ?? '0', 'benefits' => $parts['benefits'] ?? '0'];
                $line['total'] = Decimal::add($line['payroll'], $line['benefits']);

                foreach (['payroll', 'benefits', 'total'] as $key) {
                    $totals[$currency][$key] = Decimal::trim(Decimal::add($totals[$currency][$key] ?? '0', $line[$key]));
                    $line[$key] = Decimal::trim($line[$key]);
                }

                $lines[] = $line;
            }

            $rows[] = [
                'employee' => ['id' => $employee->id, 'employee_number' => $employee->employee_number, 'name' => $employee->name],
                'costs' => $lines,
            ];
        }

        return ['month' => $month->format('Y-m'), 'totals' => $totals, 'employees' => $rows];
    }

    /**
     * @param  array<int, array<string, array<string, string>>>  $costs
     */
    private function add(array &$costs, int $employeeId, string $currency, string $part, ?string $amount): void
    {
        $costs[$employeeId][$currency][$part] = Decimal::add($costs[$employeeId][$currency][$part] ?? '0', $amount ?? '0');
    }
}
