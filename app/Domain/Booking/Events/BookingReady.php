<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Models\Booking;

/**
 * A room, office or hall booking's check-in window has opened
 * (BookingCheckInService::EARLY_CHECK_IN_MINUTES before its start): the
 * home card `active_booking` appears as `ready`. Sent once per booking
 * (`ready_announced_at`, bookings:announce-ready).
 */
class BookingReady extends MemberUpdate
{
    public readonly int $bookingId;

    public function __construct(Booking $booking)
    {
        parent::__construct((int) $booking->user_id);

        $this->bookingId = (int) $booking->id;
    }

    public function broadcastAs(): string
    {
        return 'booking.ready';
    }

    public function broadcastWith(): array
    {
        return [
            'booking_id' => $this->bookingId,
        ];
    }
}
