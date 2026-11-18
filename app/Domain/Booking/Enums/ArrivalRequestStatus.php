<?php

namespace App\Domain\Booking\Enums;

enum ArrivalRequestStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Expired = 'expired';
}
