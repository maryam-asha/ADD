<?php

namespace App\Domain\Booking\Enums;

/** What reception confirms when it confirms an arrival request. */
enum ArrivalRequestPurpose: string
{
    // "I'm here" for a booking reception checks in — a room, an office, a hall.
    case Arrival = 'arrival';
    // A coworking session the member scanned for at a kiosk: it starts when reception confirms.
    case CoworkingStart = 'coworking_start';
}
