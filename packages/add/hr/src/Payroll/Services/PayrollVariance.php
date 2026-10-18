<?php

namespace Add\Hr\Payroll\Services;

use Add\Hr\Payroll\Enums\PayrollRunKind;
use Add\Hr\Payroll\Enums\PayrollRunStatus;
use Add\Hr\Payroll\Models\PayrollRun;
use Add\Hr\Payroll\Models\Payslip;
use Add\Hr\Support\Decimal;

/**
 * Each payslip against the same employee's in the last approved regular run
 * before it: what changed, and whether it changed by more than
 * hr.payroll.variance_threshold_percent. New employees and leavers are
 * listed too.
 */
class PayrollVariance
{
    /**
     * @return array{previous_run_id: ?int, rows: list<array<string, mixed>>}
     */
    public function for(PayrollRun $run): array
    {
        $previous = PayrollRun::query()
            ->where('kind', PayrollRunKind::Regular->value)
            ->whereIn('status', [PayrollRunStatus::Approved->value, PayrollRunStatus::Paid->value])
            ->where(fn ($q) => $q->where('year', '<', $run->year)->orWhere(fn ($q) => $q->where('year', $run->year)->where('month', '<', $run->month)))
            ->orderByDesc('year')->orderByDesc('month')
            ->first();

        $now = $run->payslips()->with('lines')->get()->keyBy('employee_id');
        $before = $previous?->payslips()->with('lines')->get()->keyBy('employee_id') ?? collect();
        $threshold = (string) config('hr.payroll.variance_threshold_percent', 10);
        $rows = [];

        foreach ($now->keys()->merge($before->keys())->unique()->sort() as $employeeId) {
            $current = $now->get($employeeId);
            $last = $before->get($employeeId);
            $change = Decimal::sub($current?->net ?? '0', $last?->net ?? '0');
            $percent = $last === null || Decimal::cmp($last->net, '0') === 0 ? null : Decimal::round(Decimal::div(Decimal::mul($change, '100'), $last->net));

            $rows[] = [
                'employee_id' => $employeeId,
                'name' => ($current ?? $last)->snapshot['name'] ?? null,
                'status' => $last === null ? 'new' : ($current === null ? 'left' : 'continuing'),
                'gross' => ['previous' => $last?->gross, 'current' => $current?->gross],
                'net' => ['previous' => $last?->net, 'current' => $current?->net, 'change' => Decimal::round($change), 'percent' => $percent],
                'components' => $this->components($current, $last),
                'flagged' => $last === null || $current === null || ($percent !== null && Decimal::cmp(ltrim($percent, '-'), $threshold) > 0),
            ];
        }

        return ['previous_run_id' => $previous?->id, 'rows' => $rows];
    }

    /**
     * @return list<array{code: string, previous: string, current: string}>
     */
    private function components(?Payslip $current, ?Payslip $last): array
    {
        $sum = fn (?Payslip $slip) => $slip === null ? collect() : $slip->lines->groupBy('code')->map(fn ($lines) => Decimal::add('0', ...$lines->pluck('amount')->all()));
        $a = $sum($last);
        $b = $sum($current);

        return $a->keys()->merge($b->keys())->unique()->sort()->values()
            ->map(fn (string $code) => ['code' => $code, 'previous' => Decimal::trim($a->get($code, '0')), 'current' => Decimal::trim($b->get($code, '0'))])
            ->filter(fn (array $row) => Decimal::cmp($row['previous'], $row['current']) !== 0)
            ->values()
            ->all();
    }
}
