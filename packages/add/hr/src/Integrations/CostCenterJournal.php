<?php

namespace Add\Hr\Integrations;

use Add\Hr\Contracts\PayrollAccountingExporter;
use Add\Hr\Payroll\Enums\PayComponentKind;
use Add\Hr\Payroll\Models\PayrollRun;
use Add\Hr\Support\Decimal;
use Add\Hr\Timesheets\Models\CostCenter;
use Add\Hr\Timesheets\Services\CostDistribution;

/**
 * The default journal: every cost (earnings, employer contributions,
 * benefits in kind) debited to `expense:{code}`, split across the
 * employee's cost centers for the month (approved timesheets, else the
 * planned split, else unallocated); what is owed credited to
 * `payable:{code}` and the net pay to `payable:net_pay`. Debits equal
 * credits per payslip.
 */
class CostCenterJournal implements PayrollAccountingExporter
{
    public function __construct(private readonly CostDistribution $distribution) {}

    public function rows(PayrollRun $run): array
    {
        $codes = CostCenter::query()->pluck('code', 'id');
        $rows = [];

        foreach ($run->payslips()->with(['lines', 'employee'])->orderBy('employee_id')->get() as $payslip) {
            $shares = $this->distribution->sharesFor($payslip->employee, $run->monthStart(), $run->monthEnd()) ?: [0 => 1.0];
            $number = (string) ($payslip->snapshot['employee_number'] ?? $payslip->employee_id);

            foreach ($payslip->lines as $line) {
                if ($line->kind !== PayComponentKind::Deduction) {
                    $remaining = $line->amount;
                    $last = array_key_last($shares);

                    foreach ($shares as $centerId => $share) {
                        $part = $centerId === $last ? $remaining : Decimal::round(Decimal::mul($line->amount, (string) $share));
                        $remaining = Decimal::sub($remaining, $part);
                        $rows[] = $this->row("expense:{$line->code}", $codes[$centerId] ?? '', $number, Decimal::trim($part), '0', $run->currency);
                    }
                }

                if ($line->kind !== PayComponentKind::Earning) {
                    $rows[] = $this->row("payable:{$line->code}", '', $number, '0', $line->amount, $run->currency);
                }
            }

            $rows[] = $this->row('payable:net_pay', '', $number, '0', $payslip->net, $run->currency);
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private function row(string $account, string $costCenter, string $employee, string $debit, string $credit, string $currency): array
    {
        return ['account' => $account, 'cost_center' => $costCenter, 'employee_number' => $employee, 'debit' => $debit, 'credit' => $credit, 'currency' => $currency];
    }
}
