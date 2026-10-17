<?php

namespace Add\Hr\Core\Enums;

enum ContractType: string
{
    case OpenEnded = 'open_ended';
    case FixedTerm = 'fixed_term';
    case Hourly = 'hourly';
    case Internship = 'internship';
}
