<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Models\Booking;

/**
 * A room booking was checked in — by reception or the member's door QR,
 * with no acceptance step: the home card turns `active`.
 */
class BookingStarted extends MemberUpdate
{
    public readonly int $bookingId;

    public readonly ?string $checkedInVia;

    public function __construct(Booking $booking)
    {
        parent::__construct((int) $booking->user_id);

        $this->bookingId = (int) $booking->id;
        $this->checkedInVia = $booking->checked_in_via?->value;
    }

    public function broadcastAs(): string
    {
        return 'booking.started';
    }

    public function broadcastWith(): array
    {
        return [
            'booking_id' => $this->bookingId,
            'checked_in_via' => $this->checkedInVia,
        ];
    }
}
