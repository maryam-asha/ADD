<?php

namespace App\Console\Commands;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingApprovalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gives a booking whose time has come its outcome
 * (docs/decisions/booking-outcomes-and-extension.md):
 *
 * - A request still pending when it starts can never be checked in, so it
 *   lapses — rejected, with no operator, its points refunded. Each one is
 *   locked and re-checked by BookingApprovalService::lapse(), so a staff
 *   decision in the same instant wins, and one that fails is logged while
 *   the rest carry on.
 * - A confirmed booking nobody checked into is a no-show once it ends. What
 *   was paid stays paid; what was not is no longer owed — a no-show is not a
 *   payable status. One conditional update: it writes nothing but the status,
 *   and a check-in after end_at is refused, so nothing can race it.
 */
class CloseElapsedBookings extends Command
{
    protected $signature = 'bookings:close-elapsed';

    protected $description = 'Lapse bookings still awaiting approval at their start, and mark ended bookings nobody checked into as no-shows.';

    public function handle(BookingApprovalService $approvals): int
    {
        $lapsed = 0;

        Booking::query()
            ->where('status', BookingStatus::Pending)
            ->where('start_at', '<=', now())
            ->select('id')
            ->chunkById(100, function ($bookings) use ($approvals, &$lapsed) {
                foreach ($bookings as $booking) {
                    try {
                        $lapsed += $approvals->lapse($booking) ? 1 : 0;
                    } catch (Throwable $e) {
                        Log::error('[Bookings] could not lapse an undecided booking', [
                            'booking_id' => $booking->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $noShows = Booking::query()
            ->where('status', BookingStatus::Confirmed)
            ->whereNull('checked_in_at')
            ->where('end_at', '<=', now())
            ->update(['status' => BookingStatus::NoShow->value, 'updated_at' => now()]);

        if ($lapsed > 0 || $noShows > 0) {
            $this->info("Lapsed {$lapsed} undecided booking(s); marked {$noShows} no-show(s).");
        }

        return self::SUCCESS;
    }
}
