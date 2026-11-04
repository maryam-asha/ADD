<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Models\Booking;

/**
 * A room booking closed — at its end, or earlier by reception — and was
 * billed; `payment_state` is final, after anything owed was taken by the
 * method it was booked with (ClosedBookingSettlement). The home card goes;
 * `unpaid`: the booking joins home's `unpaid` list.
 */
class BookingEnded extends MemberUpdate
{
    public readonly int $bookingId;

    public readonly ?string $paymentState;

    public function __construct(Booking $booking)
    {
        parent::__construct((int) $booking->user_id);

        $this->bookingId = (int) $booking->id;
        $this->paymentState = $booking->payment_state?->value;
    }

    public function broadcastAs(): string
    {
        return 'booking.ended';
    }

    public function broadcastWith(): array
    {
        return [
            'booking_id' => $this->bookingId,
            'payment_state' => $this->paymentState,
        ];
    }
}
