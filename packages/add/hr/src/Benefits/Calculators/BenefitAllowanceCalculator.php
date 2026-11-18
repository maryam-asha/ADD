<?php

namespace Add\Hr\Benefits\Calculators;

use Add\Hr\Benefits\Enums\BenefitGrantStatus;
use Add\Hr\Benefits\Enums\BenefitKind;
use Add\Hr\Benefits\Models\Benefit;
use Add\Hr\Benefits\Models\BenefitGrant;
use Add\Hr\Benefits\Services\BenefitGranter;
use Add\Hr\Payroll\Calculation\LineResult;
use Add\Hr\Payroll\Calculation\PayrollContext;
use Add\Hr\Payroll\Contracts\ComponentCalculator;
use Add\Hr\Payroll\Enums\PayrollRunKind;
use Add\Hr\Payroll\Models\PayComponent;

/**
 * Pays the cash-allowance benefits linked to this component: first makes
 * sure the month's grants exist (a regular run does not wait for the
 * granting job), then pays every grant still pending up to the month's end
 * — a grant a cancelled run never paid is paid by the next one. Approval
 * marks them fulfilled (SettleBenefitAllowances).
 */
class BenefitAllowanceCalculator implements ComponentCalculator
{
    public const BASIS_PREFIX = 'benefit grant #';

    public function __construct(private readonly BenefitGranter $granter) {}

    public function calculate(PayComponent $component, PayrollContext $context): array
    {
        $benefits = Benefit::query()
            ->where('pay_component_id', $component->id)
            ->where('kind', BenefitKind::CashAllowance->value)
            ->get();

        if ($benefits->isEmpty()) {
            return [];
        }

        if ($context->run->kind === PayrollRunKind::Regular) {
            $this->granter->grantFor($context->employee, $context->run->monthStart(), $benefits);
        }

        return BenefitGrant::query()
            ->where('employee_id', $context->employee->id)
            ->whereIn('benefit_id', $benefits->modelKeys())
            ->where('status', BenefitGrantStatus::Pending->value)
            ->where('granted_on', '<=', $context->run->monthEnd()->toDateString())
            ->orderBy('granted_on')
            ->orderBy('id')
            ->get()
            ->map(fn (BenefitGrant $grant) => new LineResult(
                $component,
                $context->money($context->convert($grant->amount, $grant->currency ?? $context->run->currency)),
                basis: self::BASIS_PREFIX.$grant->id,
                originalAmount: $grant->amount,
                originalCurrency: $grant->currency,
            ))
            ->values()
            ->all();
    }
}
