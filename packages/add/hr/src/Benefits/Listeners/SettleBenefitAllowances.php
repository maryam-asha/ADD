<?php

namespace Add\Hr\Benefits\Listeners;

use Add\Hr\Benefits\Calculators\BenefitAllowanceCalculator;
use Add\Hr\Benefits\Enums\BenefitGrantStatus;
use Add\Hr\Benefits\Models\BenefitGrant;
use Add\Hr\Payroll\Events\PayrollRunApproved;
use Add\Hr\Payroll\Models\PayslipLine;
use Add\Hr\Support\HrException;

/**
 * The cash allowances an approved run paid are fulfilled by its payslips.
 */
class SettleBenefitAllowances
{
    public function handle(PayrollRunApproved $event): void
    {
        $lines = PayslipLine::query()
            ->whereIn('payslip_id', $event->run->payslips()->select('id'))
            ->where('basis', 'like', BenefitAllowanceCalculator::BASIS_PREFIX.'%')
            ->get(['payslip_id', 'basis']);

        foreach ($lines as $line) {
            $settled = BenefitGrant::query()
                ->whereKey((int) substr($line->basis, strlen(BenefitAllowanceCalculator::BASIS_PREFIX)))
                ->where('status', BenefitGrantStatus::Pending->value)
                ->update(['status' => BenefitGrantStatus::Fulfilled->value, 'payslip_id' => $line->payslip_id, 'fulfilled_at' => now()]);

            // Paid by another run or cancelled since this run was calculated:
            // refusing rolls the approval back until it is recalculated.
            if ($settled === 0) {
                HrException::fail('hr::payroll.allowance_already_settled');
            }
        }
    }
}
