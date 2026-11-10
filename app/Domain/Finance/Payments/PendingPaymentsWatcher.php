<?php

namespace App\Domain\Finance\Payments;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Events\PendingPaymentsChanged;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Methods\CashMethod;
use App\Domain\Kitchen\Services\KitchenOrderSettlement;
use App\Services\Realtime\RealtimeBroadcaster;
use App\Support\Enums\EventPaymentState;
use App\Support\Enums\EventRegistrationStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps the reception desk's pending-payments list live. What is owed is
 * read off the rows themselves (OutstandingItems) and changes on many paths —
 * a settlement by any method, a desk checkout, an auto-close, a cancellation,
 * a registration, a cash hold lapsing — so rather than each path announcing
 * it, a save that changes whether a row is owed, or how much, sends one
 * PendingPaymentsChanged, after commit like every real-time update. A save
 * that leaves that alone (a check-in of a paid booking, a note) sends nothing.
 *
 * owes() restates OutstandingItems' rules on one row's attributes: keep the
 * two together. A booking's partial payments (an extension owed after a paid
 * booking) do not show on its own columns, so its payments are watched too.
 *
 * Kitchen orders join the list when their session or booking closes
 * (OutstandingItems::kitchenOrdersOwed), and leave it when paid — a payment
 * settling, watched above. So a bill closing is a change whenever an accepted
 * order on it is unpaid, even when the bill itself owes nothing (paid, or
 * covered by the plan).
 */
class PendingPaymentsWatcher
{
    private const MODELS = [Booking::class, WalkinSession::class, EventAttendee::class];

    public function __construct(private readonly RealtimeBroadcaster $realtime) {}

    public static function register(): void
    {
        foreach (self::MODELS as $model) {
            $model::created(fn (Model $row) => app(self::class)->owes($row, $row->getAttributes()) ? app(self::class)->changed($row) : null);
            $model::updated(fn (Model $row) => app(self::class)->updated($row));
            $model::deleted(fn (Model $row) => app(self::class)->owes($row, $row->getAttributes()) ? app(self::class)->changed($row) : null);
        }

        // Settled by any method (what it paid for may stay owed in part), and
        // a plan held for cash opened or lapsed.
        Payment::created(fn (Payment $payment) => self::isHeldPlan($payment) ? app(self::class)->changed($payment) : null);
        Payment::updated(function (Payment $payment) {
            if ($payment->wasChanged('settled_at') || (self::isHeldPlan($payment) && $payment->wasChanged('status'))) {
                app(self::class)->changed($payment);
            }
        });
    }

    public function updated(Model $row): void
    {
        $before = $this->owes($row, $row->getRawOriginal());
        $after = $this->owes($row, $row->getAttributes());

        if ($before !== $after || ($after && $row->wasChanged('price_points')) || $this->closedWithUnpaidOrders($row)) {
            $this->changed($row);
        }
    }

    /** A session or booking just closed with accepted kitchen orders still unpaid: they are now on the desk's list. */
    private function closedWithUnpaidOrders(Model $row): bool
    {
        return ($row instanceof Booking || $row instanceof WalkinSession)
            && $row->wasChanged('checked_out_at')
            && $row->getRawOriginal('checked_out_at') === null
            && $row->getAttribute('checked_out_at') !== null
            && app(KitchenOrderSettlement::class)->owesFor($row);
    }

    /**
     * Whether a row in this state is on the desk's list — OutstandingItems'
     * bookingsOwed(), sessionsOwed() and registrationsOwed(), on raw values.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function owes(Model $row, array $attributes): bool
    {
        if ((int) ($attributes['price_points'] ?? 0) <= 0) {
            return false;
        }

        $paymentState = (string) ($attributes['payment_state'] ?? '');

        return match (true) {
            $row instanceof Booking => $paymentState === PaymentState::Unpaid->value
                && in_array((string) ($attributes['status'] ?? ''), array_map(fn (BookingStatus $s) => $s->value, BookingStatus::payable()), true),
            $row instanceof WalkinSession => $paymentState === PaymentState::Unpaid->value
                && ($attributes['checked_out_at'] ?? null) !== null,
            $row instanceof EventAttendee => $paymentState === EventPaymentState::Unpaid->value
                && (string) ($attributes['status'] ?? '') === EventRegistrationStatus::Approved->value,
            default => false,
        };
    }

    public function changed(Model $row): void
    {
        $memberId = $row->getAttribute('user_id');

        $this->realtime->send(new PendingPaymentsChanged($memberId === null ? null : (int) $memberId));
    }

    private static function isHeldPlan(Payment $payment): bool
    {
        return $payment->method === CashMethod::KEY && $payment->purpose === PaymentPurpose::PlanPurchase;
    }
}
