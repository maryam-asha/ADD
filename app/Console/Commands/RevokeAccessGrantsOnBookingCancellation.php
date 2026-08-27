<?php

namespace App\Console\Commands;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Services\PasscodeIssuanceService;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Closes the gap between a booking being cancelled and its access grant
 * being revoked — BookingCancellationService::cancel() has no interaction
 * with the Access domain at all (final whole-branch review, C1), so a
 * same-day grant issued ahead of a booking's cancellation window would
 * otherwise survive the booking's own cancellation indefinitely. Polls
 * Booking's already-public status/accessGrants() from the Access domain's
 * side rather than the other way around — BookingCancellationService is
 * deliberately untouched.
 */
class RevokeAccessGrantsOnBookingCancellation extends Command
{
    protected $signature = 'access:revoke-grants-on-booking-cancellation';

    protected $description = 'Revoke every issued/activated access grant for a booking that has since been cancelled.';

    public function handle(PasscodeIssuanceService $issuance): int
    {
        Booking::where('status', BookingStatus::Cancelled)
            ->whereHas('accessGrants', fn ($q) => $q->whereIn('status', [AccessGrantStatus::Issued, AccessGrantStatus::Activated]))
            ->chunkById(100, function ($bookings) use ($issuance) {
                foreach ($bookings as $booking) {
                    try {
                        $issuance->revokeForBooking($booking);
                    } catch (\Throwable $e) {
                        Log::error('Failed to revoke access grants for a cancelled booking', [
                            'booking_id' => $booking->id, 'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return self::SUCCESS;
    }
}
