<?php

namespace App\Domain\Booking\Enums;

/**
 * Who started a session: reception at the desk (or activating the booking's
 * door code on arrival), the member scanning the kiosk, or the member opening
 * their booked room's door with its QR.
 */
enum SessionStartSource: string
{
    case Reception = 'reception';
    case Scan = 'scan';
    case Door = 'door';
}
