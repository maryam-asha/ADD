<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Models\Booking;

/**
 * A booking is awaiting staff approval: the desk's approval queue re-fetches.
 */
class BookingPendingApproval extends ReceptionUpdate
{
    public readonly int $bookingId;

    public readonly int $memberId;

    public readonly int $spaceId;

    public function __construct(Booking $booking)
    {
        $this->bookingId = (int) $booking->id;
        $this->memberId = (int) $booking->user_id;
        $this->spaceId = (int) $booking->space_id;
    }

    public function broadcastAs(): string
    {
        return 'booking.pending_approval';
    }

    public function broadcastWith(): array
    {
        return [
            'booking_id' => $this->bookingId,
            'member_id' => $this->memberId,
            'space_id' => $this->spaceId,
            'status' => 'pending',
        ];
    }
}
