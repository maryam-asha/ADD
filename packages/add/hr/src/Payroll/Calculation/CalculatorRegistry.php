<?php

namespace Add\Hr\Payroll\Calculation;

use Add\Hr\Payroll\Calculators\FixedCalculator;
use Add\Hr\Payroll\Calculators\LoanInstallmentCalculator;
use Add\Hr\Payroll\Calculators\OvertimeCalculator;
use Add\Hr\Payroll\Calculators\PerAttendanceDayCalculator;
use Add\Hr\Payroll\Calculators\PercentOfCalculator;
use Add\Hr\Payroll\Calculators\RateTableCalculator;
use Add\Hr\Payroll\Calculators\UnpaidDaysCalculator;
use Add\Hr\Payroll\Contracts\ComponentCalculator;
use Add\Hr\Support\HrException;

/**
 * Calculators by key: the built-in ones plus hr.payroll.calculators.
 */
class CalculatorRegistry
{
    private const BUILT_IN = [
        'fixed' => FixedCalculator::class,
        'per_attendance_day' => PerAttendanceDayCalculator::class,
        'percent_of' => PercentOfCalculator::class,
        'rate_table' => RateTableCalculator::class,
        'overtime' => OvertimeCalculator::class,
        'unpaid_days' => UnpaidDaysCalculator::class,
        'loan_installment' => LoanInstallmentCalculator::class,
    ];

    /**
     * @return array<string, class-string<ComponentCalculator>>
     */
    public function all(): array
    {
        return [...self::BUILT_IN, ...(array) config('hr.payroll.calculators', [])];
    }

    public function get(string $key): ComponentCalculator
    {
        $class = $this->all()[$key] ?? HrException::fail('hr::payroll.unknown_calculator', ['key' => $key]);

        return app($class);
    }
}
