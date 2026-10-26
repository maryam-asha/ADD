<?php

namespace App\Domain\Booking\Enums;

enum CoworkingStartOutcome: string
{
    // Filed for reception: the session starts when reception confirms it.
    case AwaitingConfirmation = 'awaiting_confirmation';
    case ConfirmationRequired = 'confirmation_required';
    // A booking that is reception's to check in: reception was told instead.
    case ArrivalRequested = 'arrival_requested';
}
