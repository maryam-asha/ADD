<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Events\BookingCreated;
use App\Domain\Booking\Models\Booking;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Services\Realtime\RealtimeBroadcaster;
use Illuminate\Support\Facades\DB;

/**
 * Tells the member their booking is confirmed, and how it was or will be
 * paid (BookingPaymentLine). Sent when a booking reaches `confirmed` without
 * an approval — at creation, by the member or by the desk, in a batch, or
 * when its prepayment hold is released — never while it may still vanish
 * unpaid. An approved booking is told by BookingNotifier::approved().
 *
 * Sent after the transaction commits, and read again then, so the payment
 * line sees what was saved (the desk records cash after the booking exists).
 *
 * The card that confirmed a held booking is told by this notice alone: the
 * payment's own "payment received" is not sent as well (supersedes()).
 * Bound scoped, so what it remembers lasts one request or job.
 */
class ConfirmedBookingNotice
{
    /** @var array<int, true> bookings this request announced */
    private array $announced = [];

    public function __construct(
        private readonly BookingNotifier $notifier,
        private readonly RealtimeBroadcaster $realtime,
    ) {}

    public function send(Booking $booking, bool $byReception = false): void
    {
        $this->announced[$booking->id] = true;

        DB::afterCommit(function () use ($booking, $byReception) {
            $fresh = Booking::query()->with(['space', 'user'])->find($booking->id);

            if ($fresh !== null) {
                $this->notifier->confirmed($fresh, $byReception);
            }
        });

        $this->realtime->send(new BookingCreated($booking));
    }

    /** @param  list<Booking>  $bookings  one member's batch, the confirmed ones */
    public function sendBatch(array $bookings): void
    {
        if ($bookings === []) {
            return;
        }

        $ids = array_map(fn (Booking $booking) => $booking->id, $bookings);

        DB::afterCommit(function () use ($ids) {
            $fresh = Booking::query()->with(['space', 'user'])->whereKey($ids)->orderBy('start_at')->get()->all();

            if ($fresh !== []) {
                $this->notifier->confirmedBatch($fresh);
            }
        });

        $this->realtime->send(...array_map(fn (Booking $b) => new BookingCreated($b), $bookings));
    }

    /**
     * Whether this notice already tells the member about $payment: the
     * payment for a booking this request confirmed. Asked once per payment,
     * so it is forgotten once asked.
     */
    public function supersedes(Payment $payment): bool
    {
        if ($payment->purpose !== PaymentPurpose::Booking || $payment->payable_type !== (new Booking)->getMorphClass()) {
            return false;
        }

        $id = (int) $payment->payable_id;

        if (! isset($this->announced[$id])) {
            return false;
        }

        unset($this->announced[$id]);

        return true;
    }
}
