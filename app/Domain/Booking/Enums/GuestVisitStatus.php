<?php

namespace App\Domain\Booking\Enums;

/**
 * Where a guest visit stands. A visit is free while `in_grace`; at its
 * grace end it becomes a walk-in (`converted`) unless reception checked the
 * guest out first (`left`) or the host refused to pay for them (`declined`,
 * an unregistered guest only — reception then deals with them).
 */
enum GuestVisitStatus: string
{
    case InGrace = 'in_grace';
    case Declined = 'declined';
    case Left = 'left';
    case Converted = 'converted';

    /** The guest is still inside on the visit itself, not on a session. */
    public function isActive(): bool
    {
        return $this === self::InGrace || $this === self::Declined;
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return [self::InGrace->value, self::Declined->value];
    }
}
