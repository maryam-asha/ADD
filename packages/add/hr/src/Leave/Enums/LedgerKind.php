<?php

namespace Add\Hr\Leave\Enums;

enum LedgerKind: string
{
    case Opening = 'opening';
    case Accrual = 'accrual';
    case Usage = 'usage';
    case Reversal = 'reversal';
    case Adjustment = 'adjustment';
    case Expiry = 'expiry';
    case Encashment = 'encashment';
}
