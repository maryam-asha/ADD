<?php

namespace App\Domain\Finance\Payments\Fulfillers;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\PaymentMethodRegistry;
use App\Domain\Finance\Services\PointConversionService;

/**
 * Paying what a coworking (walk-in) session owes once it has closed. Its
 * price is only known then: closure writes `price_points` from the same
 * price it derives `amount_owed` from, after any plan coverage.
 */
class WalkinSessionFulfiller extends SessionPaymentFulfiller
{
    public function __construct(
        PaymentMethodRegistry $methods,
        private readonly PointConversionService $points,
    ) {
        parent::__construct($methods);
    }

    public function purpose(): PaymentPurpose
    {
        return PaymentPurpose::CoworkingSession;
    }

    /**
     * Sessions closed before `price_points` existed have only the money
     * figure; it is turned back into points rounding up, as a debit rounds.
     */
    public function pointsDue(Payment $payment): int
    {
        if ($payment->amount_points !== null) {
            return $payment->amount_points;
        }

        $session = $this->session($payment);

        if ($session->price_points !== null) {
            return $session->price_points;
        }

        return $session->amount_owed === null ? 0 : $this->points->usdToPointsCeil((string) $session->amount_owed);
    }

    public function describe(Payment $payment): string
    {
        return "Coworking session in space #{$this->session($payment)->space_id}";
    }

    public function assertPayable(Payment $payment): void
    {
        $session = $this->session($payment);

        if ($session->checked_out_at === null) {
            throw new ReceptionActionException('api.reception.not_yet_checked_out');
        }

        $this->assertNotPaid($session);

        if ($this->pointsDue($payment) <= 0) {
            throw new ReceptionActionException('api.payment.nothing_due');
        }
    }

    protected function session(Payment $payment): WalkinSession
    {
        return $this->lockedPayable($payment, WalkinSession::class);
    }
}
