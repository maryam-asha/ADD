<?php

namespace App\Domain\Finance\Payments;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\PendingBookingNotice;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Support\Enums\EventRegistrationStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * A booking or registration made with a prepaid method is held — status
 * `awaiting_payment` — until its card goes through or the member chooses
 * another method; otherwise it is cancelled at `payment_due_at`
 * (docs/decisions/prepayment-all-or-nothing.md).
 */
class PrepaymentHold
{
    public function __construct(private readonly PendingBookingNotice $pendingNotice) {}

    /** When a hold made now ends: the window, never past the start. */
    public function dueAt(CarbonInterface $start): CarbonInterface
    {
        $due = now()->addMinutes((int) config('payments.prepayment_window_minutes', 15));

        return $due->lessThan($start) ? $due : $start->copy();
    }

    /**
     * How it is paid is settled — paid, or a method paid later chosen: the
     * hold becomes what the operation would have been without it, and its
     * deadline goes. Anything not held is left alone, so every caller may
     * call this unconditionally.
     *
     * The caller's copy is not trusted: the row is read again under a lock
     * and acted on only if it is still held, so a copy that went stale — the
     * expiry job cancelled the hold meanwhile — can never bring a cancelled
     * booking back. The caller's copy is brought up to date either way.
     */
    public function release(Booking|EventAttendee $record): void
    {
        DB::transaction(function () use ($record) {
            $locked = $record::query()->whereKey($record->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            if ($locked instanceof Booking && $locked->status === BookingStatus::AwaitingPayment) {
                $status = $locked->required_approval ? BookingStatus::Pending : BookingStatus::Confirmed;
                $locked->forceFill(['status' => $status, 'payment_due_at' => null])->save();

                if ($status === BookingStatus::Pending) {
                    $this->pendingNotice->send($locked);
                }
            } elseif ($locked instanceof EventAttendee && $locked->status === EventRegistrationStatus::AwaitingPayment) {
                $locked->forceFill(['status' => EventRegistrationStatus::Approved, 'payment_due_at' => null])->save();
            }

            $record->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
