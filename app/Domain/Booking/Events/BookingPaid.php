<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\CurrentBookingLocator;

/**
 * A closed room booking is paid — from the wallet, by card, or in cash at
 * the desk: its home card (active_booking, awaiting_payment) goes.
 */
class BookingPaid extends MemberUpdate
{
    public readonly int $bookingId;

    /** The booking's card state in home's active_booking after this change (CurrentBookingLocator::stateOf); null = no card. */
    public readonly ?string $state;

    public function __construct(Booking $booking)
    {
        parent::__construct((int) $booking->user_id);

        $this->bookingId = (int) $booking->id;
        $this->state = app(CurrentBookingLocator::class)->stateOf($booking);
    }

    public function broadcastAs(): string
    {
        return 'booking.paid';
    }

    public function broadcastWith(): array
    {
        return [
            'booking_id' => $this->bookingId,
            'state' => $this->state,
        ];
    }
}
