<?php

namespace Add\Hr\Payroll\Calculators;

use Add\Hr\Payroll\Calculation\LineResult;
use Add\Hr\Payroll\Calculation\PayrollContext;
use Add\Hr\Payroll\Contracts\ComponentCalculator;
use Add\Hr\Payroll\Models\PayComponent;
use Add\Hr\Support\Decimal;
use Add\Hr\Time\Enums\DayStatus;
use Add\Hr\Time\Models\AttendanceDay;

/**
 * The compensation amount per day present (an attendance day `present`).
 */
class PerAttendanceDayCalculator implements ComponentCalculator
{
    public function calculate(PayComponent $component, PayrollContext $context): array
    {
        $line = $context->compensationFor($component);

        if ($line === null) {
            return [];
        }

        $days = AttendanceDay::query()
            ->where('employee_id', $context->employee->id)
            ->whereBetween('work_date', [$context->from->toDateString(), $context->to->toDateString()])
            ->where('status', DayStatus::Present->value)
            ->count();

        if ($days === 0) {
            return [];
        }

        $original = Decimal::mul($line->amount, $days);

        return [new LineResult($component, $context->money($context->convert($original, $line->currency)),
            basis: "{$days} days", quantity: (string) $days, rate: $line->amount,
            originalAmount: $original, originalCurrency: $line->currency)];
    }
}
