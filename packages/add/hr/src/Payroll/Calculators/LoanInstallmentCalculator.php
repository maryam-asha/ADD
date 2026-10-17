<?php

namespace Add\Hr\Payroll\Calculators;

use Add\Hr\Payroll\Calculation\LineResult;
use Add\Hr\Payroll\Calculation\PayrollContext;
use Add\Hr\Payroll\Contracts\ComponentCalculator;
use Add\Hr\Payroll\Enums\InstallmentStatus;
use Add\Hr\Payroll\Enums\LoanStatus;
use Add\Hr\Payroll\Models\LoanInstallment;
use Add\Hr\Payroll\Models\PayComponent;

/**
 * The installments of active loans due in the run's month.
 */
class LoanInstallmentCalculator implements ComponentCalculator
{
    public function calculate(PayComponent $component, PayrollContext $context): array
    {
        return LoanInstallment::query()
            ->where('year', $context->run->year)
            ->where('month', $context->run->month)
            ->where('status', InstallmentStatus::Due->value)
            ->whereHas('loan', fn ($q) => $q->where('employee_id', $context->employee->id)->where('status', LoanStatus::Active->value))
            ->with('loan')
            ->get()
            ->map(fn (LoanInstallment $installment) => new LineResult(
                $component,
                $context->money($context->convert($installment->amount, $installment->loan->currency)),
                basis: "loan #{$installment->loan_id}",
                originalAmount: $installment->amount,
                originalCurrency: $installment->loan->currency,
            ))
            ->values()
            ->all();
    }
}
