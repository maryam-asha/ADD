<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Models\Booking;
use App\Support\Time\LocalTime;

/**
 * A booking runs longer: `end_at` is its new end, in the district's offset.
 * A room closes itself at that end now (bookings:close-ended-rooms).
 */
class BookingExtended extends MemberUpdate
{
    public readonly int $bookingId;

    public readonly string $endAt;

    public function __construct(Booking $booking)
    {
        parent::__construct((int) $booking->user_id);

        $this->bookingId = (int) $booking->id;
        $this->endAt = app(LocalTime::class)->toLocal($booking->end_at)->toIso8601String();
    }

    public function broadcastAs(): string
    {
        return 'booking.extended';
    }

    public function broadcastWith(): array
    {
        return [
            'booking_id' => $this->bookingId,
            'end_at' => $this->endAt,
        ];
    }
}
