<?php

namespace Add\Hr\Payroll\Calculators;

use Add\Hr\Payroll\Calculation\LineResult;
use Add\Hr\Payroll\Calculation\PayrollContext;
use Add\Hr\Payroll\Contracts\ComponentCalculator;
use Add\Hr\Payroll\Models\PayComponent;
use Add\Hr\Support\Decimal;

/**
 * config.percent of the sum of earlier lines (config.components); an
 * employee's compensation line for the component overrides the percent.
 */
class PercentOfCalculator implements ComponentCalculator
{
    public function calculate(PayComponent $component, PayrollContext $context): array
    {
        $percent = $context->compensationFor($component)?->amount ?? $component->setting('percent');
        $base = $context->totalOf((array) $component->setting('components', []));

        if ($percent === null || Decimal::cmp($base, '0') <= 0) {
            return [];
        }

        return [new LineResult($component, $context->money(Decimal::div(Decimal::mul($base, $percent), '100')),
            basis: Decimal::trim($percent).'%', quantity: Decimal::round($base), rate: Decimal::trim($percent))];
    }
}
