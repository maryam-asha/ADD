<?php

namespace App\Domain\Booking\Services;

use App\Domain\Access\Enums\AccessSourceType;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Booking\Enums\SessionStartSource;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A booked room has no kiosk scan: the member's arrival shows up at its door
 * instead — reception activating the booking's door code, or the member
 * opening the door with its QR. The first of those checks the booking in,
 * through BookingCheckInService like every other check-in, so its rules (not
 * too early, not ended, approved and paid-for, within business hours) still
 * decide; a door opened outside them stays open and simply starts nothing.
 *
 * Never throws: whether a session starts must not decide whether the door
 * opens or the code is activated.
 */
class AccessArrivalCheckIn
{
    public function __construct(private readonly BookingCheckInService $checkIns) {}

    public function forGrant(AccessGrant $grant, SessionStartSource $via): void
    {
        // Only a booking's own grant is an arrival for a booking; a company's
        // tenancy code opens an office nobody booked.
        if ($grant->source_type !== AccessSourceType::Booking || $grant->source_id === null) {
            return;
        }

        try {
            DB::transaction(function () use ($grant, $via) {
                // Locked and re-read: two opens in the same instant (a double
                // tap, or the desk activating as the member scans) check in once.
                $booking = Booking::query()->whereKey($grant->source_id)->lockForUpdate()->first();

                if ($booking === null || $booking->checked_in_at !== null) {
                    return;
                }

                $this->checkIns->checkIn($booking, $via);
            });
        } catch (ReceptionActionException $e) {
            Log::info('[Access] door arrival did not check the booking in', [
                'booking_id' => $grant->source_id,
                'via' => $via->value,
                'reason' => $e->messageKey,
            ]);
        }
    }
}
