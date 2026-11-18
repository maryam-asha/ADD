<?php

namespace App\Domain\Booking\Enums;

enum TerminationSource: string
{
    case Reception = 'reception';
    case Auto = 'auto';
    // An early walk-in closed because the member's booking began.
    case HandedOver = 'handed_over';
    // The member checked out of a room, office or hall booking from the app.
    case Member = 'member';
}
