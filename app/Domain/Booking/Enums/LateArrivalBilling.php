<?php

namespace App\Domain\Booking\Enums;

/**
 * Where an unpaid coworking booking's bill starts when its member arrives
 * late (CoworkingBookingPolicy): at the arrival, or at the booking's start.
 * A paid booking is never refunded for arriving late, whichever applies.
 */
enum LateArrivalBilling: string
{
    case Arrival = 'arrival';
    case BookingStart = 'booking_start';
}
