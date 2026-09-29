<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\SessionStartSource;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\Kiosk;
use App\Domain\Foundation\Services\BusinessHoursService;

/**
 * The one place a booking is checked in — by reception at the desk, via a
 * confirmed arrival request, or by the member scanning the kiosk
 * (docs/decisions/coworking-self-service-sessions.md) — so all three apply
 * exactly the same rules.
 */
class BookingCheckInService
{
    public function __construct(private readonly BusinessHoursService $businessHours) {}

    public function checkIn(Booking $booking, SessionStartSource $via = SessionStartSource::Reception, ?Kiosk $kiosk = null): void
    {
        if ($booking->status === BookingStatus::Cancelled) {
            throw new ReceptionActionException('api.reception.already_cancelled', 409);
        }

        if ($booking->status === BookingStatus::Pending) {
            throw new ReceptionActionException('api.booking.check_in_requires_approval', 409);
        }

        if ($booking->status === BookingStatus::Rejected) {
            throw new ReceptionActionException('api.booking.check_in_rejected', 409);
        }

        if ($booking->checked_in_at !== null) {
            throw new ReceptionActionException('api.reception.already_checked_in', 409);
        }

        if (! $this->businessHours->isWithinBusinessHours(now(), $booking->space->building->branch)) {
            throw new ReceptionActionException('api.reception.outside_business_hours');
        }

        $booking->forceFill([
            'checked_in_at' => now(),
            'checked_in_via' => $via,
            'kiosk_id' => $kiosk?->id,
        ])->save();
    }
}
