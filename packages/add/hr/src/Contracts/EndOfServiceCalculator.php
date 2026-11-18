<?php

namespace Add\Hr\Contracts;

use Add\Hr\Core\Models\Employee;
use Carbon\CarbonImmutable;

/**
 * End-of-service entitlement — the rule is the law's, so it is the host's.
 */
interface EndOfServiceCalculator
{
    /**
     * @return array{service_years: string, monthly_basis: string, days: string, amount: string, currency: string, rule: string}
     */
    public function estimate(Employee $employee, CarbonImmutable $asOf): array;
}
