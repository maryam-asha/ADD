<?php

namespace App\Domain\Booking\Enums;

enum CoworkingStartOutcome: string
{
    case Started = 'started';
    case ConfirmationRequired = 'confirmation_required';
    // A booking that is reception's to check in: reception was told instead.
    case ArrivalRequested = 'arrival_requested';
}
