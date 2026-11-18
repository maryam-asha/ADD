<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Models\Booking;

/**
 * A booking was confirmed (at creation or approval): the desk's schedule
 * and upcoming lists re-fetch.
 */
class BookingCreated extends ReceptionUpdate
{
    public readonly int $bookingId;

    public readonly int $memberId;

    public readonly int $spaceId;

    public readonly string $status;

    public function __construct(Booking $booking)
    {
        $this->bookingId = (int) $booking->id;
        $this->memberId = (int) $booking->user_id;
        $this->spaceId = (int) $booking->space_id;
        $this->status = (string) $booking->status->value;
    }

    public function broadcastAs(): string
    {
        return 'booking.created';
    }

    public function broadcastWith(): array
    {
        return [
            'booking_id' => $this->bookingId,
            'member_id' => $this->memberId,
            'space_id' => $this->spaceId,
            'status' => $this->status,
        ];
    }
}
