<?php

namespace App\Domain\Booking\Enums;

/** Who started a session: reception at the desk, or the member scanning the kiosk. */
enum SessionStartSource: string
{
    case Reception = 'reception';
    case Scan = 'scan';
}
