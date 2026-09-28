<?php

namespace App\Domain\Booking\Enums;

/**
 * A calendar day's bookability at a glance, for the month grid.
 *
 * `Closed` and `Full` are deliberately distinct: closed means the branch
 * has no open period for the date at all (either an explicit
 * BusinessHourException or a weekday with no schedule), while full means
 * the branch is open but every slot is already taken. The app shows the
 * red "closed on this date" banner for the former only — a full day is
 * still a valid date to look at, just not to book.
 *
 * Like SlotState this is a reasoning enum, never a column.
 */
enum DayState: string
{
    case Available = 'available';
    case Closed = 'closed';
    case Full = 'full';
    case Past = 'past';
    // Beyond booking.max_days_ahead: open, but not yet bookable.
    case OutOfRange = 'out_of_range';

    /**
     * Whether the month grid lets the member tap this date.
     */
    public function isSelectable(): bool
    {
        return $this === self::Available;
    }

    /**
     * Only a closed day carries the banner; a full or past day does not.
     */
    public function showsClosedBanner(): bool
    {
        return $this === self::Closed;
    }
}
