<?php

namespace App\Console\Commands;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Events\BookingReady;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingCheckInService;
use App\Domain\Foundation\Enums\SpaceType;
use App\Services\Realtime\RealtimeBroadcaster;
use Illuminate\Console\Command;

/**
 * A room, office or hall booking whose check-in window has opened
 * (BookingCheckInService::EARLY_CHECK_IN_MINUTES before its start) tells the
 * member's app, so its home card appears as `ready` without a refresh. Once
 * per booking: `ready_announced_at` is claimed row by row, so two runs, or a
 * run that overlaps, announce it once. The FCM reminder of a booking about
 * to start is notifications:send-reminders', untouched.
 */
class AnnounceReadyBookings extends Command
{
    protected $signature = 'bookings:announce-ready';

    protected $description = 'Tell members whose room booking can now be checked in.';

    public function handle(RealtimeBroadcaster $realtime): int
    {
        Booking::query()
            ->where('status', BookingStatus::Confirmed)
            ->whereNull('checked_in_at')
            ->whereNull('ready_announced_at')
            ->where('start_at', '<=', now()->addMinutes(BookingCheckInService::EARLY_CHECK_IN_MINUTES))
            ->where('end_at', '>', now())
            ->whereHas('space', fn ($space) => $space->where('space_type', '!=', SpaceType::CoSpace))
            ->chunkById(100, function ($bookings) use ($realtime) {
                foreach ($bookings as $booking) {
                    $claimed = Booking::query()
                        ->whereKey($booking->id)
                        ->whereNull('ready_announced_at')
                        ->update(['ready_announced_at' => now()]);

                    if ($claimed === 1) {
                        $realtime->send(new BookingReady($booking));
                    }
                }
            });

        return self::SUCCESS;
    }
}
