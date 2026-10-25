<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\SessionStartSource;
use App\Domain\Booking\Enums\TerminationSource;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use Illuminate\Support\Facades\DB;

/**
 * A member who scanned in well before their coworking booking sat on a
 * walk-in; when the booking starts, that walk-in closes at the booking's
 * start and the booking is checked in from the same instant
 * (docs/decisions/coworking-self-service-sessions.md). One stretch of time,
 * billed once: the walk-in up to the start, the booking from it, and no
 * no-show for a member who was there all along.
 */
class CoworkingHandoverService
{
    public function __construct(
        private readonly SessionClosureService $closures,
        private readonly BookingCheckInService $checkIns,
    ) {}

    /**
     * True when the walk-in was handed over. A booking that can no longer
     * take it — cancelled, never confirmed, already checked in or ended — or
     * a walk-in the member already asked to end only drops the link, and
     * the walk-in runs on as any other.
     */
    public function handOver(WalkinSession $walkin): bool
    {
        return DB::transaction(function () use ($walkin) {
            $locked = WalkinSession::query()->whereKey($walkin->id)->lockForUpdate()->first();

            if ($locked === null || $locked->checked_out_at !== null || $locked->handover_booking_id === null) {
                return false;
            }

            $booking = Booking::query()->whereKey($locked->handover_booking_id)->lockForUpdate()->first();

            if ($booking === null || $booking->start_at->isFuture()) {
                return false;
            }

            if ($booking->status !== BookingStatus::Confirmed
                || $booking->checked_in_at !== null
                || ! $booking->end_at->isFuture()
                || $locked->pendingEndRequest()->exists()) {
                $locked->forceFill(['handover_booking_id' => null])->save();

                return false;
            }

            $this->closures->autoClose($locked, $booking->start_at, TerminationSource::HandedOver);
            $this->checkIns->checkIn($booking, SessionStartSource::Scan, $locked->kiosk, $booking->start_at);

            return true;
        });
    }
}
