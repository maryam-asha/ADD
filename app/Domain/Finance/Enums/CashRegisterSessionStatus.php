<?php

namespace App\Domain\Finance\Enums;

enum CashRegisterSessionStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
