<?php

namespace App\Domain\Access\Enums;

enum AccessGrantStatus: string
{
    case Issued = 'issued';
    case Activated = 'activated';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
