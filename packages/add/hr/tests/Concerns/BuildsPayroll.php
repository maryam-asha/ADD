<?php

namespace Add\Hr\Tests\Concerns;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Payroll\Models\PayComponent;
use Add\Hr\Payroll\Models\RateTable;
use Add\Hr\Payroll\Services\CompensationService;
use Add\Hr\Time\Models\TimePeriod;
use Carbon\CarbonImmutable;

/**
 * A trial pay structure, in USD:
 *
 * - basic (taxable, insurable) and transport (neither), fixed monthly;
 * - overtime: basic / 200 hours × 1.5; unpaid days: basic / 30 a day;
 * - employee insurance 7% of insurable pay up to 1000, employer 14%;
 * - income tax on taxable pay less the employee's insurance: 0% to 500,
 *   10% to 1000, 20% above;
 * - loan installments.
 *
 * Trial values only — the package holds no country's law.
 */
trait BuildsPayroll
{
    /** @var array<string, PayComponent> */
    protected array $components = [];

    protected function payStructure(): void
    {
        $ee = RateTable::query()->create(['code' => 'si_ee', 'name' => ['ar' => 'تأمينات العامل'], 'kind' => 'percent']);
        $ee->versions()->create(['effective_from' => '2020-01-01', 'rate' => 7, 'max_base' => 1000]);
        $er = RateTable::query()->create(['code' => 'si_er', 'name' => ['ar' => 'تأمينات صاحب العمل'], 'kind' => 'percent']);
        $er->versions()->create(['effective_from' => '2020-01-01', 'rate' => 14]);
        $tax = RateTable::query()->create(['code' => 'income_tax', 'name' => ['ar' => 'ضريبة الدخل'], 'kind' => 'brackets']);
        $tax->versions()->create(['effective_from' => '2020-01-01', 'brackets' => [
            ['up_to' => 500, 'rate' => 0], ['up_to' => 1000, 'rate' => 10], ['up_to' => null, 'rate' => 20],
        ]]);

        $define = fn (string $code, string $kind, string $calculator, array $extra = []) => $this->components[$code] = PayComponent::query()->create([
            'code' => $code, 'name' => ['ar' => $code], 'kind' => $kind, 'calculator' => $calculator, ...$extra,
        ])->refresh();

        $define('basic', 'earning', 'fixed', ['taxable' => true, 'insurable' => true, 'sort' => 10]);
        $define('transport', 'earning', 'fixed', ['sort' => 20]);
        $define('overtime', 'earning', 'overtime', ['applies_to_all' => true, 'taxable' => true, 'sort' => 30, 'config' => ['base_components' => ['basic'], 'hours_per_month' => 200, 'multiplier' => 1.5]]);
        $define('unpaid', 'deduction', 'unpaid_days', ['applies_to_all' => true, 'sort' => 40, 'config' => ['base_components' => ['basic'], 'days_per_month' => 30]]);
        $define('insurance_ee', 'deduction', 'rate_table', ['applies_to_all' => true, 'sort' => 50, 'config' => ['table' => 'si_ee', 'base' => 'insurable']]);
        $define('insurance_er', 'employer_contribution', 'rate_table', ['applies_to_all' => true, 'sort' => 51, 'config' => ['table' => 'si_er', 'base' => 'insurable']]);
        $define('tax', 'deduction', 'rate_table', ['applies_to_all' => true, 'sort' => 60, 'config' => ['table' => 'income_tax', 'base' => 'taxable', 'less' => ['insurance_ee'], 'in_off_cycle' => true]]);
        $define('bonus', 'earning', 'fixed', ['taxable' => true, 'sort' => 25]);
        $define('loan', 'deduction', 'loan_installment', ['applies_to_all' => true, 'sort' => 70]);
    }

    /**
     * @param  array<string, string|int|float>  $amounts  component code => monthly amount
     */
    protected function pay(Employee $employee, array $amounts, string $from = '2026-01-01', string $currency = 'USD', int|string|null $by = null): void
    {
        app(CompensationService::class)->change($employee, CarbonImmutable::parse($from), collect($amounts)->map(fn ($amount, $code) => [
            'pay_component_id' => $this->components[$code]->id,
            'amount' => $amount,
            'currency' => $currency,
        ])->values()->all(), 'test', $by);
    }

    protected function lockAttendance(int $year, int $month): void
    {
        TimePeriod::query()->updateOrCreate(['year' => $year, 'month' => $month], ['status' => 'locked']);
    }
}
