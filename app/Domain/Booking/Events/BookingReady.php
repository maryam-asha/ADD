<?php

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\CurrentBookingLocator;

/**
 * A room, office or hall booking's check-in window has opened
 * (BookingCheckInService::EARLY_CHECK_IN_MINUTES before its start): the
 * home card `active_booking` appears as `ready`. Sent once per booking
 * (`ready_announced_at`, bookings:announce-ready).
 */
class BookingReady extends MemberUpdate
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
        return 'booking.ready';
    }

    public function broadcastWith(): array
    {
        return [
            'booking_id' => $this->bookingId,
            'state' => $this->state,
        ];
    }
}
