<?php

namespace App\Console\Commands;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingCancellationService;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Exceptions\PaymeraException;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\PaymentMethodRegistry;
use App\Domain\Finance\Services\SyncPaymentStatus;
use App\Domain\Identity\Models\NotificationLog;
use App\Support\Enums\EventRegistrationStatus;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
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

    public function handle(SyncPaymentStatus $sync, PaymentMethodRegistry $methods, BookingCancellationService $cancellations): int
    {
        $cancelled = 0;
        $rejected = 0;

        Booking::query()
            ->where('status', BookingStatus::AwaitingPayment)
            ->where('payment_due_at', '<=', now())
            ->chunkById(100, function ($holds) use ($sync, $methods, $cancellations, &$cancelled) {
                foreach ($holds as $hold) {
                    try {
                        $this->lastWordFromTheGateway($sync, $methods, $hold);

                        if ($cancellations->releaseUnpaidHold($hold)) {
                            $cancelled++;
                            $this->tell($hold->user_id, 'booking.prepayment_expired');
                        }
                    } catch (Throwable $e) {
                        Log::error('[Payments] could not let an expired booking hold go', ['booking_id' => $hold->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        EventAttendee::query()
            ->where('status', EventRegistrationStatus::AwaitingPayment)
            ->where('payment_due_at', '<=', now())
            ->chunkById(100, function ($holds) use ($sync, $methods, &$rejected) {
                foreach ($holds as $hold) {
                    try {
                        $this->lastWordFromTheGateway($sync, $methods, $hold);

                        if ($this->rejectRegistration($hold)) {
                            $rejected++;
                            $this->tell($hold->user_id, 'event.registration_prepayment_expired');
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

    /**
     * Any card still pending for it is asked once more; an accepted one
     * pays it and releases the hold through the fulfiller. A gateway that
     * cannot be reached decides nothing: the hold is judged on what is known.
     */
    private function lastWordFromTheGateway(SyncPaymentStatus $sync, PaymentMethodRegistry $methods, Model $hold): void
    {
        Payment::query()
            ->forPayable($hold)
            ->where('status', PaymentStatus::Pending)
            ->whereIn('method', $methods->gatewayKeys())
            ->get()
            ->each(function (Payment $payment) use ($sync) {
                try {
                    $sync->handle($payment);
                } catch (PaymeraException) {
                    // Unreachable now; the reconciler keeps asking. A later
                    // acceptance credits the member's wallet (needs_review).
                }
            });
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

    /** A registration made without an account has no one to tell. */
    private function tell(?int $userId, string $templateKey): void
    {
        if ($userId === null) {
            return;
        }

        NotificationLog::create([
            'user_id' => $userId,
            'channel' => 'push',
            'template_key' => $templateKey,
            'status' => 'sent',
        ]);
    }
}
