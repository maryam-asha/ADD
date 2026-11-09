<?php

namespace App\Domain\Access\Enums;

enum AccessRevokeReason: string
{
    // The space went into maintenance; a booking gets a new code once it reopens.
    case Maintenance = 'maintenance';
    // The booking was cancelled.
    case Cancellation = 'cancellation';
    // The desk issued a new code in its place.
    case Replaced = 'replaced';
    // The lease ended, was terminated or changed its last day.
    case Lease = 'lease';
    // Stopped by hand from the dashboard; never re-issued on its own.
    case Staff = 'staff';
    // The member checked out of the booking before its end.
    case CheckedOut = 'checked_out';
}
