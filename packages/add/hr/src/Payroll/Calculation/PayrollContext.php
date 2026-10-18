<?php

namespace Add\Hr\Payroll\Calculation;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Payroll\Enums\PayComponentKind;
use Add\Hr\Payroll\Models\Compensation;
use Add\Hr\Payroll\Models\PayComponent;
use Add\Hr\Payroll\Models\PayrollRun;
use Add\Hr\Support\Decimal;
use Carbon\CarbonImmutable;

/**
 * Everything a calculator needs about one employee in one run: the days
 * they were employed in the month, the lines computed so far (components
 * run in `sort` order, so a tax sees the earnings before it), and money
 * helpers in the run's currency.
 */
final class PayrollContext
{
    /** @var list<LineResult> */
    public array $lines = [];

    /** @var list<string> */
    public array $warnings = [];

    public function __construct(
        public readonly PayrollRun $run,
        public readonly Employee $employee,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly int $employedDays,
        public readonly int $daysInMonth,
        private readonly RunRates $rates,
    ) {}

    public function convert(string $amount, string $currency): string
    {
        return Decimal::mul($amount, $this->rates->rate($currency));
    }

    public function rateFor(string $currency): string
    {
        return $this->rates->rate($currency);
    }

    public function money(string $amount): string
    {
        return Decimal::round($amount, (int) config('hr.payroll.decimals', 2));
    }

    /**
     * @param  list<string>  $codes
     */
    public function totalOf(array $codes): string
    {
        return Decimal::add('0', ...array_map(
            fn (LineResult $line) => $line->amount,
            array_filter($this->lines, fn (LineResult $line) => in_array($line->component->code, $codes, true)),
        ));
    }

    /**
     * Earnings so far (cash and in kind), optionally only the taxable or the
     * insurable ones.
     */
    public function earnings(?string $flag = null): string
    {
        return Decimal::add('0', ...array_map(
            fn (LineResult $line) => $line->amount,
            array_filter($this->lines, fn (LineResult $line) => in_array($line->component->kind, [PayComponentKind::Earning, PayComponentKind::InKind], true)
                && ($flag === null || $line->component->{$flag})),
        ));
    }

    /**
     * The employee's monthly pay on the given components on the last
     * employed day of the month, in the run's currency.
     *
     * @param  list<string>  $codes
     */
    public function monthlyPay(array $codes): string
    {
        $ids = PayComponent::query()->whereIn('code', $codes)->pluck('id');

        return Decimal::add('0', ...Compensation::query()
            ->where('employee_id', $this->employee->id)
            ->whereIn('pay_component_id', $ids)
            ->overlapping($this->to, $this->to)
            ->get()
            ->map(fn (Compensation $line) => $this->convert($line->amount, $line->currency))
            ->all());
    }

    public function compensationFor(PayComponent $component): ?Compensation
    {
        return Compensation::query()
            ->where('employee_id', $this->employee->id)
            ->where('pay_component_id', $component->id)
            ->overlapping($this->from, $this->to)
            ->latest('effective_from')
            ->first();
    }
}
