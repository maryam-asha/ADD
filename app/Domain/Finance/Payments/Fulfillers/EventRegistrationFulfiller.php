<?php

namespace App\Domain\Finance\Payments\Fulfillers;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Contracts\PaymentFulfiller;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Support\Enums\EventPaymentState;
use App\Support\Enums\EventRegistrationStatus;

/**
 * Paying for a seat at a paid event. The price is the one recorded on the
 * registration when it was made, from the general balance — the wording and
 * pool event registration has always debited.
 *
 * Only an approved registration is paid for: a pending one may still be
 * turned down, and a rejected one holds no seat. Paying marks it paid, which
 * is what the door checks.
 *
 * Lives in Finance, not Ecosystem: Finance may read the event side, the
 * event side may not import Finance (DomainLayerBoundaryTest).
 */
class EventRegistrationFulfiller implements PaymentFulfiller
{
    public function purpose(): PaymentPurpose
    {
        return PaymentPurpose::EventRegistration;
    }

    public function pointsDue(Payment $payment): int
    {
        if ($payment->amount_points !== null) {
            return $payment->amount_points;
        }

        return (int) ($this->attendee($payment)->price_points ?? 0);
    }

    public function walletCategory(): WalletTransactionCategory
    {
        return WalletTransactionCategory::General;
    }

    public function describe(Payment $payment): string
    {
        $event = Event::query()->find($this->attendee($payment)->event_id);
        $title = is_array($event?->title)
            ? ($event->title['en'] ?? $event->title['ar'] ?? "Event #{$event->id}")
            : (string) $event?->title;

        return "Event registration: {$title}";
    }

    public function assertPayable(Payment $payment): void
    {
        $attendee = $this->attendee($payment);

        if ($attendee->status !== EventRegistrationStatus::Approved) {
            throw new ReceptionActionException('api.payment.registration_not_payable');
        }

        if ($attendee->payment_state === EventPaymentState::Paid) {
            throw new ReceptionActionException('api.payment.already_paid', 409);
        }

        if (Event::query()->whereKey($attendee->event_id)->value('status') === 'cancelled') {
            throw new ReceptionActionException('api.payment.registration_not_payable');
        }

        if ((int) ($attendee->price_points ?? 0) <= 0) {
            throw new ReceptionActionException('api.payment.nothing_due');
        }
    }

    public function fulfil(Payment $payment): void
    {
        $this->attendee($payment)->forceFill([
            'payment_state' => EventPaymentState::Paid,
            'paid_at' => now(),
            // How it was actually paid: a seat registered by card and paid
            // by wallet through its ticket is not left reading the card.
            'payment_method' => (string) $payment->method,
        ])->save();
    }

    /**
     * Read fresh and locked, like the session fulfillers: the checks and the
     * state change see the row as it is now.
     */
    private function attendee(Payment $payment): EventAttendee
    {
        $attendee = $payment->payable_type === (new EventAttendee)->getMorphClass() && $payment->payable_id !== null
            ? EventAttendee::query()->whereKey($payment->payable_id)->lockForUpdate()->first()
            : null;

        return $attendee ?? throw new ReceptionActionException('api.payment.payable_not_found', 404);
    }
}
