<?php

namespace Add\Hr\Benefits\Enums;

enum BenefitKind: string
{
    /** Money paid through payroll, as an earning line. */
    case CashAllowance = 'cash_allowance';

    /** Units delivered by the host's BenefitFulfiller (wallet points, hours). */
    case InKindCredit = 'in_kind_credit';

    /** Recorded for its cost only (insurance cover, a membership). */
    case Entitlement = 'entitlement';
}
