<?php

namespace Add\Hr\Payroll\Contracts;

use Add\Hr\Payroll\Calculation\LineResult;
use Add\Hr\Payroll\Calculation\PayrollContext;
use Add\Hr\Payroll\Models\PayComponent;

/**
 * Computes one pay component for one employee in one run. Registered by
 * key in hr.payroll.calculators; a host adds its own the same way. There
 * is no formula language: a rule is a class, reviewed and tested.
 */
interface ComponentCalculator
{
    /**
     * @return list<LineResult>
     */
    public function calculate(PayComponent $component, PayrollContext $context): array;
}
