<?php

namespace Database\Seeders;

use Add\Hr\Payroll\Models\PayComponent;
use Add\Hr\Payroll\Models\RateTable;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * A TRIAL pay structure for trying payroll out in a local or staging
 * environment: basic and transport pay, overtime, unpaid days, employee and
 * employer insurance, a progressive income tax and loan installments.
 *
 * The rates are placeholders, not Syrian law. They are data, so once the
 * legal values are confirmed they are entered from the HR settings
 * screens (new rate-table versions); nothing in code changes. Never run in
 * production: `php artisan db:seed --class=HrPayrollTrialSeeder`.
 */
class HrPayrollTrialSeeder extends Seeder
{
    private const NOTE = 'TRIAL value — replace after legal review';

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('HrPayrollTrialSeeder holds trial values and never runs in production.');
        }

        $this->table('si_employee', ['ar' => 'تأمينات العامل (تجريبي)', 'en' => 'Social insurance, employee (trial)'], 'percent', ['rate' => 7]);
        $this->table('si_employer', ['ar' => 'تأمينات صاحب العمل (تجريبي)', 'en' => 'Social insurance, employer (trial)'], 'percent', ['rate' => 14]);
        $this->table('income_tax', ['ar' => 'ضريبة الدخل (تجريبي)', 'en' => 'Income tax (trial)'], 'brackets', ['brackets' => [
            ['up_to' => 100, 'rate' => 0],
            ['up_to' => 500, 'rate' => 5],
            ['up_to' => 1000, 'rate' => 10],
            ['up_to' => null, 'rate' => 15],
        ], 'currency' => 'USD']);

        $this->component('basic', ['ar' => 'الراتب الأساسي', 'en' => 'Basic salary'], 'earning', 'fixed', 10, taxable: true, insurable: true);
        $this->component('transport', ['ar' => 'بدل مواصلات', 'en' => 'Transport allowance'], 'earning', 'fixed', 20);
        $this->component('bonus', ['ar' => 'مكافأة', 'en' => 'Bonus'], 'earning', 'fixed', 25, taxable: true);
        $this->component('overtime', ['ar' => 'عمل إضافي', 'en' => 'Overtime'], 'earning', 'overtime', 30, all: true, taxable: true,
            config: ['base_components' => ['basic'], 'hours_per_month' => 208, 'multiplier' => 1.5]);
        $this->component('unpaid_days', ['ar' => 'أيام بلا أجر', 'en' => 'Unpaid days'], 'deduction', 'unpaid_days', 40, all: true,
            config: ['base_components' => ['basic'], 'days_per_month' => 30]);
        $this->component('si_employee', ['ar' => 'تأمينات العامل', 'en' => 'Social insurance (employee)'], 'deduction', 'rate_table', 50, all: true,
            config: ['table' => 'si_employee', 'base' => 'insurable']);
        $this->component('si_employer', ['ar' => 'تأمينات صاحب العمل', 'en' => 'Social insurance (employer)'], 'employer_contribution', 'rate_table', 51, all: true,
            config: ['table' => 'si_employer', 'base' => 'insurable']);
        $this->component('income_tax', ['ar' => 'ضريبة الدخل', 'en' => 'Income tax'], 'deduction', 'rate_table', 60, all: true,
            config: ['table' => 'income_tax', 'base' => 'taxable', 'less' => ['si_employee'], 'in_off_cycle' => true]);
        $this->component('loan', ['ar' => 'قسط سلفة', 'en' => 'Loan installment'], 'deduction', 'loan_installment', 70, all: true);
    }

    /**
     * @param  array<string, string>  $name
     * @param  array<string, mixed>  $version
     */
    private function table(string $code, array $name, string $kind, array $version): void
    {
        $table = RateTable::query()->firstOrCreate(['code' => $code], ['name' => $name, 'kind' => $kind]);

        if ($table->versions()->doesntExist()) {
            $table->versions()->create(['effective_from' => '2026-01-01', 'note' => self::NOTE, ...$version]);
        }
    }

    /**
     * @param  array<string, string>  $name
     * @param  array<string, mixed>  $config
     */
    private function component(string $code, array $name, string $kind, string $calculator, int $sort, bool $all = false, bool $taxable = false, bool $insurable = false, array $config = []): void
    {
        PayComponent::query()->firstOrCreate(['code' => $code], [
            'name' => $name,
            'kind' => $kind,
            'calculator' => $calculator,
            'config' => $config ?: null,
            'applies_to_all' => $all,
            'taxable' => $taxable,
            'insurable' => $insurable,
            'sort' => $sort,
        ]);
    }
}
