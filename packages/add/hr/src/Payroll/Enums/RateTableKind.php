<?php

namespace Add\Hr\Payroll\Enums;

enum RateTableKind: string
{
    case Percent = 'percent';
    case Brackets = 'brackets';
}
