<?php

namespace App\Domain\Booking\Enums;

enum SessionEndRequestStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    /** The session closed another way — at the desk or by auto-close — first. */
    case Superseded = 'superseded';
}
