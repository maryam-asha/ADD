<?php

namespace App\Domain\Leasing\Enums;

/**
 * Stored states only. "Starts on …" is an active lease whose starts_on is
 * ahead, and "ending soon" is a filter on ends_on — neither is a state.
 */
enum OfficeLeaseStatus: string
{
    case Active = 'active';
    case Ended = 'ended';
    case Terminated = 'terminated';
}
