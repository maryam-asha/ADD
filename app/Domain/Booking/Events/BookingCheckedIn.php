<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Models\Booking;

/** A room booking was checked in, from the desk or its door: the desk's lists re-fetch. */
class BookingCheckedIn extends ReceptionUpdate
{
    public readonly int $bookingId;

    public readonly int $memberId;

    public function __construct(Booking $booking)
    {
        $this->bookingId = (int) $booking->id;
        $this->memberId = (int) $booking->user_id;
    }

    public function broadcastAs(): string
    {
        return 'booking.checked_in';
    }

    public function broadcastWith(): array
    {
        return [
            'booking_id' => $this->bookingId,
            'member_id' => $this->memberId,
        ];
    }
}
