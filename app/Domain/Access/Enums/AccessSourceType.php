<?php

namespace App\Domain\Access\Enums;

enum AccessSourceType: string
{
    case Booking = 'booking';
    case Tenancy = 'tenancy';
}
