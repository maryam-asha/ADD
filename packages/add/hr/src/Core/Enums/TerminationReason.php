<?php

namespace Add\Hr\Core\Enums;

enum TerminationReason: string
{
    case Resignation = 'resignation';
    case Dismissal = 'dismissal';
    case EndOfContract = 'end_of_contract';
    case Retirement = 'retirement';
    case Death = 'death';
    case Other = 'other';
}
