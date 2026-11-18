<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\SpaceType;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Releases every unpaid coworking booking whose member has not come by its
 * release instant (CoworkingBookingPolicy::releaseAt()), and tells each
 * member (product owner, 2026-11-02). Each one is locked and re-checked by
 * BookingCancellationService::releaseUnpaidNoShow(); one that fails is
 * logged while the rest carry on.
 */
class UnpaidNoShowReleaser
{
    public function __construct(
        private readonly BookingCancellationService $cancellations,
        private readonly CoworkingBookingPolicy $policy,
        private readonly BookingNotifier $notices,
    ) {}

    /** @return int how many were released */
    public function run(): int
    {
        $released = 0;

        Booking::query()
            ->where('status', BookingStatus::Confirmed)
            ->where('payment_state', PaymentState::Unpaid)
            ->whereNull('checked_in_at')
            ->where('start_at', '<=', now())
            ->where('end_at', '>', now())
            ->whereHas('space', fn ($query) => $query->where('space_type', SpaceType::CoSpace))
            ->with(['space', 'user'])
            ->chunkById(100, function ($bookings) use (&$released) {
                foreach ($bookings as $booking) {
                    if ($this->policy->releaseAt($booking)->isFuture()) {
                        continue;
                    }

                    try {
                        if ($this->cancellations->releaseUnpaidNoShow($booking)) {
                            $released++;
                            $this->notices->releasedNoShow($booking, $this->policy->releaseAt($booking));
                        }
                    } catch (Throwable $e) {
                        Log::error('[Bookings] could not release an unpaid coworking no-show', [
                            'booking_id' => $booking->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $released;
    }
}
