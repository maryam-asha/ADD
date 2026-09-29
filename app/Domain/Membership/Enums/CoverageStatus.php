<?php

namespace App\Domain\Membership\Enums;

enum CoverageStatus: string
{
    case Covered = 'covered';
    case NotCovered = 'not_covered';
}
