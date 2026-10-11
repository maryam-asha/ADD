<?php

namespace App\Domain\Finance\Payments\Fulfillers;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Contracts\PaymentFulfiller;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use LogicException;

/**
 * The extra points a checked-in booking costs when it is made longer.
 *
 * The amount is the difference BookingExtensionService computes, passed in
 * when the payment is opened; there is no price here to re-read. Paying it
 * changes nothing on the booking — the booking is already paid, and the
 * extension service writes its new end and state itself. The row exists so
 * a later refund returns these points too, to the wallet that paid them.
 */
class BookingExtensionFulfiller implements PaymentFulfiller
{
    public function purpose(): PaymentPurpose
    {
        return PaymentPurpose::BookingExtension;
    }

    public function pointsDue(Payment $payment): int
    {
        return $payment->amount_points
            ?? throw new LogicException('An extension payment is opened with its amount (context `points`).');
    }

    public function walletCategory(): WalletTransactionCategory
    {
        return WalletTransactionCategory::SpaceSpecific;
    }

    /**
     * The wording the extension's own debit has always carried.
     */
    public function describe(Payment $payment): string
    {
        return "Booking #{$payment->payable_id} extension";
    }

    public function assertPayable(Payment $payment): void
    {
        $booking = $payment->payable_type === (new Booking)->getMorphClass()
            ? Booking::query()->find($payment->payable_id)
            : null;

        if ($booking === null) {
            throw new ReceptionActionException('api.payment.payable_not_found', 404);
        }

        if (! $booking->status->isPayable()) {
            throw new ReceptionActionException('api.payment.booking_not_payable');
        }
    }

    public function fulfil(Payment $payment): void {}
}
