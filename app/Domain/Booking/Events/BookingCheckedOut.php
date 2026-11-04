<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Models\Booking;

/** A room booking closed, and how it stands for payment: the desk's lists re-fetch. */
class BookingCheckedOut extends ReceptionUpdate
{
    public readonly int $bookingId;

    public readonly int $memberId;

    public readonly ?string $paymentState;

    public function __construct(Booking $booking)
    {
        $this->bookingId = (int) $booking->id;
        $this->memberId = (int) $booking->user_id;
        $this->paymentState = $booking->payment_state?->value;
    }

    public function broadcastAs(): string
    {
        return 'booking.checked_out';
    }

    public function broadcastWith(): array
    {
        return [
            'booking_id' => $this->bookingId,
            'member_id' => $this->memberId,
            'payment_state' => $this->paymentState,
        ];
    }
}
