<?php

namespace App\Domain\Booking\Enums;

/**
 * Why a slot cannot be booked — or that it can.
 *
 * This is a presentation-and-reasoning enum, not a column: no migration
 * writes it, so it is deliberately absent from
 * tests/Guards/EnumColumnsHaveBackedEnumCastsTest's concern. Each case
 * carries the lang key `BookingCreationService` would throw for the same
 * window, so the greyed-out chip in the app and the eventual 422 say the
 * identical sentence (docs/decisions/booking-availability-and-calendar.md).
 */
enum SlotState: string
{
    case Available = 'available';
    case Booked = 'booked';
    case OutsideHours = 'outside_hours';
    case Buffer = 'buffer';
    case AtCapacity = 'at_capacity';
    case Past = 'past';
    case TooSoon = 'too_soon';
    case TooFarAhead = 'too_far_ahead';

    /**
     * The message key the write path uses for this same condition.
     *
     * Available has none — there is nothing to explain about a slot that
     * can be booked.
     */
    public function reasonKey(): ?string
    {
        return match ($this) {
            self::Available => null,
            self::Booked => 'api.booking.slot_unavailable',
            self::OutsideHours => 'api.reception.outside_business_hours',
            self::Buffer => 'api.booking.buffer_conflict',
            self::AtCapacity => 'api.booking.space_at_capacity',
            self::Past => 'api.booking.slot_in_past',
            self::TooSoon => 'api.booking.too_soon',
            self::TooFarAhead => 'api.booking.too_far_ahead',
        };
    }

    public function isBookable(): bool
    {
        return $this === self::Available;
    }
}
