<?php

namespace App\Domain\Kitchen\Enums;

/** Who cancelled a pending order: the member, or the system when its bill closed first. */
enum CancelledBy: string
{
    case Member = 'member';
    case System = 'system';
}
