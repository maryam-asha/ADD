<?php

namespace Add\Hr\Payroll\Enums;

enum InstallmentStatus: string
{
    case Due = 'due';
    case Deducted = 'deducted';
    case Settled = 'settled';
}
