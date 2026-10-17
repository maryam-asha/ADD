<?php

namespace Add\Hr\Payroll\Enums;

/**
 * earning: paid to the employee; deduction: withheld from them; employer
 * contribution: the employer's cost on top (insurance share), not on the slip's
 * net; in kind: a benefit valued for tax and cost, not paid in cash.
 */
enum PayComponentKind: string
{
    case Earning = 'earning';
    case Deduction = 'deduction';
    case EmployerContribution = 'employer_contribution';
    case InKind = 'in_kind';
}
