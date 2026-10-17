<?php

namespace Add\Hr\Core\Enums;

/**
 * Every change to an employment is one of these, recorded through
 * EmploymentLifecycleService. Together they are the employee's career
 * timeline.
 */
enum EmploymentEventType: string
{
    case Hire = 'hire';
    case Confirmation = 'confirmation';
    case Promotion = 'promotion';
    case Transfer = 'transfer';
    case AssignmentChange = 'assignment_change';
    case ContractRenewal = 'contract_renewal';
    case Suspension = 'suspension';
    case Reinstatement = 'reinstatement';
    case Termination = 'termination';
    case Rehire = 'rehire';
}
