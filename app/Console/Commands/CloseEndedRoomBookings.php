<?php

namespace App\Console\Commands;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Foundation\Enums\SpaceType;
use Illuminate\Console\Command;

/**
 * A checked-in room, office or hall booking closes itself at its end — its
 * extended end, once extended — with termination_source = auto, at exactly
 * `end_at`: the member never ends a room from the app, and reception may
 * close it earlier (product owner, 2026-11-03). It costs its booked price
 * either way (SessionClosureService), and what it owes is settled by the
 * method it was booked with. Coworking bookings end through reception's
 * confirmation, not here.
 */
class CloseEndedRoomBookings extends Command
{
    protected $signature = 'bookings:close-ended-rooms';

    protected $description = 'Close every checked-in room booking whose end has come.';

    public function handle(SessionClosureService $closures): int
    {
        Booking::query()
            ->where('status', BookingStatus::Confirmed)
            ->whereNotNull('checked_in_at')
            ->whereNull('checked_out_at')
            ->where('end_at', '<=', now())
            ->whereHas('space', fn ($space) => $space->where('space_type', '!=', SpaceType::CoSpace))
            ->chunkById(100, function ($bookings) use ($closures) {
                foreach ($bookings as $booking) {
                    try {
                        $closures->autoClose($booking, $booking->end_at);
                    } catch (ReceptionActionException $e) {
                        // Refused under autoClose()'s lock — closed at the
                        // desk since this run read the page. One such booking
                        // must not abort the rest of the run.
                        report($e);
                        $this->warn("Skipped booking #{$booking->id}: {$e->messageKey}");
                    }
                }
            });

        return self::SUCCESS;
    }
}
