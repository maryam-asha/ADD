<?php

namespace Add\Hr\Payroll\Calculators;

use Add\Hr\Payroll\Calculation\LineResult;
use Add\Hr\Payroll\Calculation\PayrollContext;
use Add\Hr\Payroll\Contracts\ComponentCalculator;
use Add\Hr\Payroll\Enums\RateTableKind;
use Add\Hr\Payroll\Models\PayComponent;
use Add\Hr\Payroll\Models\RateTable;
use Add\Hr\Support\Decimal;
use Add\Hr\Support\HrException;

/**
 * A legal rate on a base: config.table names the rate table; config.base is
 * gross | taxable | insurable | components (config.components), less
 * config.less (codes of earlier lines, e.g. the employee's insurance before
 * tax). A percent table applies its rate up to its max_base; a brackets
 * table is progressive. Bands and ceilings are in the version's currency.
 */
class RateTableCalculator implements ComponentCalculator
{
    public function calculate(PayComponent $component, PayrollContext $context): array
    {
        $table = RateTable::query()->where('code', $component->setting('table'))->first()
            ?? HrException::fail('hr::payroll.rate_table_missing', ['code' => (string) $component->setting('table')]);
        $version = $table->versionOn($context->run->monthEnd());

        if ($version === null) {
            $context->warnings[] = "no_rate_in_force:{$table->code}";

            return [];
        }

        $base = match ($component->setting('base', 'gross')) {
            'taxable' => $context->earnings('taxable'),
            'insurable' => $context->earnings('insurable'),
            'components' => $context->totalOf((array) $component->setting('components', [])),
            default => $context->earnings(),
        };
        $base = Decimal::sub($base, $context->totalOf((array) $component->setting('less', [])));

        if (Decimal::cmp($base, '0') <= 0) {
            return [];
        }

        $factor = $context->rateFor($version->currency ?? $context->run->currency);

        if ($table->kind === RateTableKind::Percent) {
            $capped = $version->max_base === null ? $base : Decimal::min($base, Decimal::mul($version->max_base, $factor));

            return [new LineResult($component, $context->money(Decimal::div(Decimal::mul($capped, $version->rate ?? '0'), '100')),
                basis: Decimal::trim($version->rate ?? '0').'%', quantity: Decimal::round($capped), rate: $version->rate)];
        }

        $previous = '0';
        $amount = '0';

        foreach ((array) $version->brackets as $bracket) {
            $upper = ($bracket['up_to'] ?? null) === null ? null : Decimal::mul((string) $bracket['up_to'], $factor);
            $slice = Decimal::sub($upper === null ? $base : Decimal::min($base, $upper), $previous);

            if (Decimal::cmp($slice, '0') <= 0) {
                break;
            }

            $amount = Decimal::add($amount, Decimal::div(Decimal::mul($slice, (string) $bracket['rate']), '100'));

            if ($upper === null || Decimal::cmp($base, $upper) <= 0) {
                break;
            }

            $previous = $upper;
        }

        return [new LineResult($component, $context->money($amount), basis: $table->code, quantity: Decimal::round($base))];
    }
}
