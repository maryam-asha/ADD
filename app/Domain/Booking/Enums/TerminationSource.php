<?php

namespace App\Domain\Booking\Enums;

enum TerminationSource: string
{
    case Reception = 'reception';
    case Auto = 'auto';
}
