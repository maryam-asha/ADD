<?php

namespace App\Domain\Foundation\Enums;

use Carbon\CarbonInterface;

/**
 * String-backed, not int-backed, to match every other enum in this
 * codebase (see build plan §A.4) — even though Carbon's own `dayOfWeek`
 * accessor is an int. `fromCarbon()` is the one place that translation
 * happens, so nothing else needs to know Carbon's numbering.
 */
enum DayOfWeek: string
{
    case Sunday = 'sunday';
    case Monday = 'monday';
    case Tuesday = 'tuesday';
    case Wednesday = 'wednesday';
    case Thursday = 'thursday';
    case Friday = 'friday';
    case Saturday = 'saturday';

    public static function fromCarbon(CarbonInterface $date): self
    {
        return self::from(strtolower($date->format('l')));
    }
}
