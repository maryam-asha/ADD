<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Events\BookingPendingApproval;
use App\Domain\Booking\Models\Booking;
use App\Services\Realtime\RealtimeBroadcaster;
use Illuminate\Support\Facades\DB;

/**
 * Tells whoever may approve bookings that one waits for their review. Sent
 * when a booking reaches `pending` — at creation, in a batch, or when its
 * prepayment hold is released (PrepaymentHold) — never while it may still
 * vanish unpaid.
 */
class PendingBookingNotice
{
    public function __construct(
        private readonly BookingNotifier $notifier,
        private readonly RealtimeBroadcaster $realtime,
    ) {}

    public function send(Booking $booking): void
    {
        // The booking may still be inside its creation transaction: read
        // its space and member once it is saved.
        DB::afterCommit(fn () => $this->notifier->pendingApproval($booking->loadMissing(['space', 'user'])));

        $this->realtime->send(new BookingPendingApproval($booking));
    }
}
