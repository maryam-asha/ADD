<?php

namespace App\Domain\Booking\Enums;

enum CoworkingStartOutcome: string
{
    case Started = 'started';
    case Existing = 'existing';
    case ConfirmationRequired = 'confirmation_required';
}
