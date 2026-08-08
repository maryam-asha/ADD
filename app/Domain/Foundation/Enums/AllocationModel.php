<?php

namespace App\Domain\Foundation\Enums;

/**
 * How a space is allocated once Booking (Phase 5) exists. Left nullable on
 * `spaces` in Phase 1 on purpose — the space_type -> allocation_model
 * mapping is business logic that belongs to that phase, not to this
 * structural one.
 */
enum AllocationModel: string
{
    case BookingHourly = 'booking_hourly';
    case BookingDaily = 'booking_daily';
    case Tenancy = 'tenancy';
    case Open = 'open';
}
