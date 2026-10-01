<?php

namespace App\Domain\Finance\Payments\Fulfillers;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\AmountCalculator;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\PaymentMethodRegistry;
use App\Domain\Finance\Services\PointConversionService;

/**
 * Paying for a booking. Its price is fixed when it is made (`price_points`,
 * after any plan discount); what is due is that price less what has been
 * paid for it already — the payment's own `amount_points` once the payment
 * is open, so what the member was quoted is what they pay.
 */
class BookingFulfiller extends SessionPaymentFulfiller
{
    public function __construct(
        PaymentMethodRegistry $methods,
        private readonly AmountCalculator $amounts,
        private readonly PointConversionService $points,
    ) {
        parent::__construct($methods);
    }

    public function purpose(): PaymentPurpose
    {
        return PaymentPurpose::Booking;
    }

    /**
     * What the booking still owes: its price less what has already been
     * paid for it (Booking::pointsPaid) — so a paid booking that was then
     * extended owes only the extension, at the desk, by card or from the
     * wallet, and nothing already paid is charged again.
     */
    public function pointsDue(Payment $payment): int
    {
        if ($payment->amount_points !== null) {
            return $payment->amount_points;
        }

        $booking = $this->session($payment);

        return max(0, $this->price($booking) - $booking->pointsPaid());
    }

    /**
     * The booking's whole price. Rows made before `price_points` existed
     * are priced again, as cancellation's refund always has.
     */
    private function price(Booking $booking): int
    {
        if ($booking->price_points !== null) {
            return $booking->price_points;
        }

        // Closed before price_points existed: what closure charged.
        if ($booking->checked_out_at !== null && $booking->amount_owed !== null) {
            return $this->points->usdToPointsCeil((string) $booking->amount_owed);
        }

        return $this->amounts->forRange($booking->space, $booking->start_at, $booking->end_at);
    }

    /**
     * The same wording the booking's own debit has always carried.
     */
    public function describe(Payment $payment): string
    {
        return "Booking for space #{$this->session($payment)->space_id}";
    }

    public function assertPayable(Payment $payment): void
    {
        $booking = $this->session($payment);

        if (in_array($booking->status, [BookingStatus::Cancelled, BookingStatus::Rejected], true)) {
            throw new ReceptionActionException('api.payment.booking_not_payable');
        }

        $this->assertNotPaid($booking);

        // A free booking (a free room, a 100% discount) has nothing to pay,
        // and a payment for nothing is refused before any row is written,
        // as a session's is.
        if ($this->pointsDue($payment) <= 0) {
            throw new ReceptionActionException('api.payment.nothing_due');
        }
    }

    protected function session(Payment $payment): Booking
    {
        return $this->lockedPayable($payment, Booking::class);
    }
}
