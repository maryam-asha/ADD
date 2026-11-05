<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Models\Booking;

/**
 * A closed room booking is paid — from the wallet, by card, or in cash at
 * the desk: its home card (active_booking, awaiting_payment) goes.
 */
class BookingPaid extends MemberUpdate
{
    public readonly int $bookingId;

    public function __construct(Booking $booking)
    {
        parent::__construct((int) $booking->user_id);

        $this->bookingId = (int) $booking->id;
    }

    public function broadcastAs(): string
    {
        return 'booking.paid';
    }

    public function broadcastWith(): array
    {
        return [
            'booking_id' => $this->bookingId,
        ];
    }
}
