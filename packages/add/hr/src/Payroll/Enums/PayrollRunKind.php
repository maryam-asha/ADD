<?php

namespace Add\Hr\Payroll\Enums;

/**
 * regular: the month's payroll, once attendance is locked. off_cycle: a
 * correction or a one-off (bonus, final settlement) made of adjustments.
 */
enum PayrollRunKind: string
{
    case Regular = 'regular';
    case OffCycle = 'off_cycle';
}
