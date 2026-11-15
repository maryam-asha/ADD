<?php

namespace App\Console\Commands;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingCancellationService;
use App\Domain\Booking\Services\BookingNotifier;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Finance\Payments\LastWordFromTheGateway;
use App\Services\Experience\EventNotifier;
use App\Support\Enums\EventRegistrationStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A booking or registration made with a prepaid method is held for
 * payments.prepayment_window_minutes. One neither paid nor switched to
 * another method by its deadline is let go — after one last word from the
 * gateway, so a card the bank accepted in the meantime pays it instead
 * (docs/decisions/prepayment-all-or-nothing.md). Each hold is locked and
 * re-read where it is let go, so a payment landing in the same instant wins.
 */
class CancelExpiredPrepayments extends Command
{
    protected $signature = 'payments:cancel-expired-prepayments';

    protected $description = 'Cancel bookings and registrations whose prepayment was not completed in time.';

    public function handle(
        LastWordFromTheGateway $gateway,
        BookingCancellationService $cancellations,
        BookingNotifier $bookingNotices,
        EventNotifier $eventNotices,
    ): int {
        $cancelled = 0;
        $rejected = 0;

        Booking::query()
            ->where('status', BookingStatus::AwaitingPayment)
            ->where('payment_due_at', '<=', now())
            ->chunkById(100, function ($holds) use ($gateway, $cancellations, $bookingNotices, &$cancelled) {
                foreach ($holds as $hold) {
                    try {
                        $gateway->ask($hold);

                        if ($cancellations->releaseUnpaidHold($hold)) {
                            $cancelled++;
                            $bookingNotices->paymentExpired($hold);
                        }
                    } catch (Throwable $e) {
                        Log::error('[Payments] could not let an expired booking hold go', ['booking_id' => $hold->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        EventAttendee::query()
            ->where('status', EventRegistrationStatus::AwaitingPayment)
            ->where('payment_due_at', '<=', now())
            ->chunkById(100, function ($holds) use ($gateway, $eventNotices, &$rejected) {
                foreach ($holds as $hold) {
                    try {
                        $gateway->ask($hold);

                        if ($this->rejectRegistration($hold)) {
                            $rejected++;
                            $eventNotices->expired($hold);
                        }
                    } catch (Throwable $e) {
                        Log::error('[Payments] could not let an expired registration hold go', ['event_attendee_id' => $hold->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        if ($cancelled > 0 || $rejected > 0) {
            $this->info("Cancelled {$cancelled} booking hold(s); rejected {$rejected} registration hold(s).");
        }

        return self::SUCCESS;
    }

    private function rejectRegistration(EventAttendee $hold): bool
    {
        return DB::transaction(function () use ($hold) {
            $locked = EventAttendee::query()->whereKey($hold->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->status !== EventRegistrationStatus::AwaitingPayment) {
                return false;
            }

            $locked->forceFill([
                'status' => EventRegistrationStatus::Rejected,
                'rejection_reason' => __('api.event.prepayment_expired'),
                // No approved_by: nobody decided this, the deadline did.
                'approved_at' => now(),
            ])->save();

            return true;
        });
    }
}
