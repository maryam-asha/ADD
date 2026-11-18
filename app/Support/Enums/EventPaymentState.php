<?php

namespace App\Support\Enums;

/**
 * Whether a paid event's registration has been paid for. Null on the row
 * when there is nothing to pay (a free event).
 *
 * Under App\Support for the same reason as EventRegistrationStatus:
 * EventAttendee (Ecosystem) casts to it and Finance's fulfiller writes it,
 * and DomainLayerBoundaryTest keeps Ecosystem from importing Finance.
 */
enum EventPaymentState: string
{
    case Paid = 'paid';
    case Unpaid = 'unpaid';
}
