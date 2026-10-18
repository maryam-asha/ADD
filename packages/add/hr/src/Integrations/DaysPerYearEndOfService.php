<?php

namespace Add\Hr\Integrations;

use Add\Hr\Contracts\EndOfServiceCalculator;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Payroll\Services\CompensationService;
use Add\Hr\Support\Decimal;
use Carbon\CarbonImmutable;

/**
 * The default rule, configurable and deliberately simple: so many days of
 * the monthly basis (hr.payroll.end_of_service.base_components, a month
 * counted as 30 days) per year of service, fractions of a year included.
 * A host with a statutory rule binds its own calculator.
 */
class DaysPerYearEndOfService implements EndOfServiceCalculator
{
    public function __construct(private readonly CompensationService $compensation) {}

    public function estimate(Employee $employee, CarbonImmutable $asOf): array
    {
        $config = (array) config('hr.payroll.end_of_service', []);
        $currency = (string) config('hr.payroll.currency', 'USD');
        $end = $employee->terminated_on !== null && $employee->terminated_on->lt($asOf) ? $employee->terminated_on : $asOf;
        $years = Decimal::div((string) max(0, (int) $employee->hired_on->diffInDays($end) + 1), '365');
        $basis = $this->compensation->monthlyTotal($employee, $end, (array) ($config['base_components'] ?? ['basic']), $currency);
        $days = Decimal::mul($years, (string) ($config['days_per_year'] ?? 30));

        return [
            'service_years' => Decimal::round($years, 4),
            'monthly_basis' => Decimal::round($basis),
            'days' => Decimal::round($days, 2),
            'amount' => Decimal::round(Decimal::mul(Decimal::div($basis, '30'), $days)),
            'currency' => $currency,
            'rule' => 'days_per_year',
        ];
    }
}
