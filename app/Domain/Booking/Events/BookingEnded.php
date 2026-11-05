<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\CurrentBookingLocator;

/**
 * A room booking closed — at its end, or earlier by reception — and was
 * billed; `payment_state` is final, after anything owed was taken by the
 * method it was booked with (ClosedBookingSettlement). The home card goes;
 * `unpaid`: it stays home's active_booking, in state awaiting_payment.
 */
class BookingEnded extends MemberUpdate
{
    public readonly int $bookingId;

    /** The booking's card state in home's active_booking after this change (CurrentBookingLocator::stateOf); null = no card. */
    public readonly ?string $state;

    public readonly ?string $paymentState;

    public function __construct(Booking $booking)
    {
        parent::__construct((int) $booking->user_id);

        $this->bookingId = (int) $booking->id;
        $this->state = app(CurrentBookingLocator::class)->stateOf($booking);
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
            'state' => $this->state,
            'payment_state' => $this->paymentState,
        ];
    }
}
