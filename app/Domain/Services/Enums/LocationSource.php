<?php

namespace App\Domain\Services\Enums;

enum LocationSource: string
{
    case Qr     = 'qr';
    case Manual = 'manual';
    case None   = 'none';
}
