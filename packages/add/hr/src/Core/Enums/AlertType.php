<?php

namespace Add\Hr\Core\Enums;

enum AlertType: string
{
    case ContractEnding = 'contract_ending';
    case ProbationEnding = 'probation_ending';
    case DocumentExpiring = 'document_expiring';
    case DocumentExpired = 'document_expired';
    case DocumentMissing = 'document_missing';
    /** The training module's: raised only while it is enabled. */
    case CertificationExpiring = 'certification_expiring';
    case CertificationExpired = 'certification_expired';
}
