<?php

namespace Add\Hr\Payroll\Calculators;

use Add\Hr\Payroll\Calculation\LineResult;
use Add\Hr\Payroll\Calculation\PayrollContext;
use Add\Hr\Payroll\Contracts\ComponentCalculator;
use Add\Hr\Payroll\Models\Compensation;
use Add\Hr\Payroll\Models\PayComponent;
use Add\Hr\Support\Decimal;

/**
 * A monthly amount from the employee's compensation, prorated by the days
 * each pay line ran in the month (a raise mid-month, a hire, a last day).
 * config.prorate = false pays the latest line in full.
 */
class FixedCalculator implements ComponentCalculator
{
    public function calculate(PayComponent $component, PayrollContext $context): array
    {
        $prorate = (bool) $component->setting('prorate', true);
        $lines = [];

        $pay = Compensation::query()
            ->where('employee_id', $context->employee->id)
            ->where('pay_component_id', $component->id)
            ->overlapping($context->from, $context->to)
            ->orderByDesc('effective_from')
            ->get();

        foreach ($pay as $line) {
            $start = $line->effective_from->max($context->from);
            $end = ($line->effective_to ?? $context->to)->min($context->to);
            $days = (int) $start->diffInDays($end) + 1;
            $original = $prorate ? Decimal::div(Decimal::mul($line->amount, $days), $context->daysInMonth) : $line->amount;

            $lines[] = new LineResult(
                $component,
                $context->money($context->convert($original, $line->currency)),
                basis: $prorate && $days < $context->daysInMonth ? "{$days}/{$context->daysInMonth}" : null,
                quantity: (string) $days,
                rate: $line->amount,
                originalAmount: Decimal::round($original, 4),
                originalCurrency: $line->currency,
            );

            if (! $prorate) {
                break;
            }
        }

        return array_reverse($lines);
    }
}
