<?php

namespace Add\Hr\Payroll\Enums;

enum LoanStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Settled = 'settled';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
