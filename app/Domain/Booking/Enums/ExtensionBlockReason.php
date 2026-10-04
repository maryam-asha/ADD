<?php

namespace App\Domain\Booking\Enums;

/**
 * Why a booking cannot be extended right now
 * (docs/decisions/booking-outcomes-and-extension.md). The first three are
 * about the booking; the last three are about the time after it.
 */
enum ExtensionBlockReason: string
{
    case SpaceDisallows = 'space_disallows';
    case NotCheckedIn = 'not_checked_in';
    case OutsideBookingTime = 'outside_booking_time';
    // Another booking (or its buffer), a co-working loan, or a full seat pool.
    case NextSlotBooked = 'next_slot_booked';
    // The lent room the member sits in goes back to being a room, and no
    // other co-working seat is free after it.
    case SeatRoomCloses = 'seat_room_closes';
    case BranchClosing = 'branch_closing';

    public function label(?string $locale = null): string
    {
        return __('api.booking.extension_reasons.'.$this->value, [], $locale);
    }

    /**
     * What POST .../extend answers with. `not_checked_in` and the conflict
     * keep the keys the app already handles.
     */
    public function messageKey(): string
    {
        return match ($this) {
            self::SpaceDisallows => 'api.booking.extension_not_allowed',
            self::NotCheckedIn => 'api.reception.not_checked_in',
            self::OutsideBookingTime => 'api.booking.extension_outside_booking_time',
            self::NextSlotBooked => 'api.booking.extension_conflict',
            self::SeatRoomCloses => 'api.booking.extension_seat_room_closes',
            self::BranchClosing => 'api.booking.extension_past_closing',
        };
    }
}
