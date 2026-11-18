<?php

namespace Add\Hr\Payroll\Calculators;

use Add\Hr\Payroll\Calculation\LineResult;
use Add\Hr\Payroll\Calculation\PayrollContext;
use Add\Hr\Payroll\Contracts\ComponentCalculator;
use Add\Hr\Payroll\Models\PayComponent;
use Add\Hr\Payroll\Models\RateTable;
use Add\Hr\Support\Decimal;
use Add\Hr\Time\Enums\OvertimeStatus;
use Add\Hr\Time\Models\OvertimeRequest;

/**
 * Approved overtime in the month × the hourly rate (monthly pay on
 * config.base_components / config.hours_per_month) × the multiplier
 * (config.multiplier, or the rate of config.multiplier_table as a percent).
 */
class OvertimeCalculator implements ComponentCalculator
{
    public function calculate(PayComponent $component, PayrollContext $context): array
    {
        $minutes = (int) OvertimeRequest::query()
            ->where('employee_id', $context->employee->id)
            ->where('status', OvertimeStatus::Approved->value)
            ->whereBetween('work_date', [$context->from->toDateString(), $context->to->toDateString()])
            ->sum('minutes');

        if ($minutes === 0) {
            return [];
        }

        $hourly = Decimal::div(
            $context->monthlyPay((array) $component->setting('base_components', ['basic'])),
            (string) $component->setting('hours_per_month', 208),
        );
        $multiplier = (string) $component->setting('multiplier', '1.5');

        if (($code = $component->setting('multiplier_table')) !== null) {
            $rate = RateTable::query()->where('code', $code)->first()?->versionOn($context->run->monthEnd())?->rate;
            $multiplier = $rate === null ? $multiplier : Decimal::div($rate, '100');
        }

        $hours = Decimal::div((string) $minutes, '60');

        return [new LineResult($component, $context->money(Decimal::mul(Decimal::mul($hourly, $hours), $multiplier)),
            basis: Decimal::round($hours).'h × '.Decimal::trim($multiplier), quantity: Decimal::round($hours, 4), rate: Decimal::round($hourly, 4))];
    }
}
