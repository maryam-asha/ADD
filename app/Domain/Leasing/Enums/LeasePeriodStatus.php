<?php

namespace App\Domain\Leasing\Enums;

enum LeasePeriodStatus: string
{
    case Upcoming = 'upcoming';
    case Invoiced = 'invoiced';
    case Cancelled = 'cancelled';
}
