<?php

namespace App\Domain\Booking\Enums;

enum BookingStatus: string
{
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
}
