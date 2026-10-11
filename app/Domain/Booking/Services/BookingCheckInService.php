<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\SessionStartSource;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\Kiosk;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Services\BusinessHoursService;

/**
 * The one place a booking is checked in — by reception at the desk, via a
 * confirmed arrival request, or by the member scanning the kiosk
 * (docs/decisions/coworking-self-service-sessions.md) — so all three apply
 * exactly the same rules.
 */
class BookingCheckInService
{
    /**
     * How long before its start a booking may be checked in, from any door.
     * A late arrival is always let in while the booking runs.
     */
    public const EARLY_CHECK_IN_MINUTES = 15;

    public function __construct(
        private readonly BusinessHoursService $businessHours,
        private readonly OpenCoworkingSessionLocator $openSessions,
    ) {}

    public function checkIn(Booking $booking, SessionStartSource $via = SessionStartSource::Reception, ?Kiosk $kiosk = null): void
    {
        if ($booking->status === BookingStatus::Cancelled) {
            throw new ReceptionActionException('api.reception.already_cancelled', 409);
        }

        // Held for its card payment: take the payment first (reception
        // settles it, which releases the hold), then check it in.
        if ($booking->status === BookingStatus::AwaitingPayment) {
            throw new ReceptionActionException('api.booking.check_in_awaiting_payment', 409);
        }

        if ($booking->status === BookingStatus::Pending) {
            throw new ReceptionActionException('api.booking.check_in_requires_approval', 409);
        }

        if ($booking->status === BookingStatus::Rejected) {
            throw new ReceptionActionException('api.booking.check_in_rejected', 409);
        }

        if ($booking->status === BookingStatus::NoShow) {
            throw new ReceptionActionException('api.booking.check_in_no_show', 409);
        }

        if ($booking->checked_in_at !== null) {
            throw new ReceptionActionException('api.reception.already_checked_in', 409);
        }

        // An ended booking nobody came to is about to become a no-show
        // (bookings:close-elapsed); refusing here as well keeps the two from
        // racing into a no-show that was checked in.
        if (! $booking->end_at->isFuture()) {
            throw new ReceptionActionException('api.booking.check_in_ended', 409);
        }

        if ($booking->start_at->greaterThan(now()->addMinutes(self::EARLY_CHECK_IN_MINUTES))) {
            throw new ReceptionActionException('api.booking.check_in_too_early', 409);
        }

        // At most one open coworking session per member, as for walk-ins
        // (WalkInCapacityService::start()); the scan returns the open one
        // before it ever gets here. Rooms and the other space types are not
        // coworking sessions.
        if ($booking->space->space_type === SpaceType::CoSpace && $this->openSessions->for($booking->user) !== null) {
            throw new ReceptionActionException('api.reception.member_already_in_session', 409);
        }

        if (! $this->businessHours->isWithinBusinessHours(now(), $booking->space->building->branch)) {
            throw new ReceptionActionException('api.reception.outside_business_hours');
        }

        // An early arrival is let in, but its session begins when the booking
        // does: `checked_in_at` is never before `start_at` (the bookings
        // table's CHECK constraint holds MySQL to the same).
        $booking->forceFill([
            'checked_in_at' => now()->max($booking->start_at),
            'checked_in_via' => $via,
            'kiosk_id' => $kiosk?->id,
        ])->save();
    }
}
