<?php

namespace App\Domain\Finance\Payments;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Enums\CouponRejection;
use App\Domain\Pricing\Exceptions\CouponRejectedException;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Services\CouponApplication;
use App\Domain\Pricing\Services\CouponEligibility;
use App\Domain\Pricing\Services\CouponLedger;
use App\Support\Presenters\CouponOutcomePresenter;
use Illuminate\Database\Eloquent\Model;

/**
 * Pays something that already exists — a booking, a closed coworking
 * session, an event registration — by the method the member chose, through
 * the one payment path (PaymentService). Each operation's own endpoint calls
 * it with its purpose, so the rule lives once
 * (docs/superpowers/specs/2026-10-06-prepayment-all-or-nothing-design.md).
 *
 * A coupon code is applied to what the record owes before the payment
 * opens (CouponApplication), as pay now always did. The code the record
 * already carries is ignored rather than refused: the app re-sends, with the
 * id, the request it made the record with — coupon included. For the same
 * reason a code that creation did not apply (it does not combine with the
 * offer the record was priced at, or a payment for it is already under way)
 * does not stop the payment: it goes ahead at the record's current price and
 * the outcome says the code was not used. Any other refusal is the member's
 * to hear, as at creation.
 *
 * Failures are not caught here: the endpoint decides how each one reads.
 */
class PayableCheckout
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly CouponEligibility $coupons,
        private readonly CouponLedger $ledger,
        private readonly CouponApplication $application,
        private readonly CouponOutcomePresenter $outcomes,
        private readonly PrepaymentHold $holds,
        private readonly PaymentMethodRegistry $methods,
    ) {}

    /**
     * @param  array{wallet_owner_type?: mixed, wallet_owner_id?: int|null}  $context
     */
    public function pay(
        PaymentPurpose $purpose,
        Model $payable,
        User $member,
        string $method,
        array $context = [],
        ?string $couponCode = null,
    ): CheckoutOutcome {
        // A hold past its deadline takes no new payment: it is about to be
        // cancelled (payments:cancel-expired-prepayments). A card already in
        // flight still settles it — that goes through the fulfiller, not here.
        if (($payable instanceof Booking || $payable instanceof EventAttendee) && $payable->awaitingPaymentExpired()) {
            throw new ReceptionActionException($payable instanceof Booking ? 'api.payment.booking_not_payable' : 'api.payment.registration_not_payable');
        }

        $coupon = $this->couponToApply($payable, $couponCode);

        $outcome = null;

        if ($coupon !== null) {
            try {
                $quote = $this->application->apply($payable, $member, $coupon);
                $outcome = $this->outcomes->fromQuote($couponCode, $quote);

                if ($quote->finalPoints === 0) {
                    // Nothing left to pay: nothing to hold for.
                    $this->releaseHold($payable);

                    return new CheckoutOutcome(null, PaymentStart::done(), $outcome);
                }
            } catch (CouponRejectedException $e) {
                $outcome = match ($e->rejection) {
                    CouponRejection::NotCombinable => $this->outcomes->notAppliedBecause($couponCode, __('api.coupon.offer_is_better')),
                    CouponRejection::PaymentInProgress => $this->outcomes->notAppliedBecause($couponCode, __($e->messageKey, $e->params)),
                    default => throw $e,
                };
            }
        }

        $locale = app()->getLocale();

        ['payment' => $payment, 'start' => $start] = $this->payments->start($purpose, $member, $method, array_filter([
            'payable' => $payable,
            'wallet_owner_type' => $context['wallet_owner_type'] ?? null,
            'wallet_owner_id' => $context['wallet_owner_id'] ?? null,
            // The gateway page speaks the member's language.
            'lang' => in_array($locale, ['ar', 'en'], true) ? $locale : null,
        ], fn ($value) => $value !== null));

        // A method paid later (cash) settles how it is paid without paying
        // it: the hold goes now. The wallet already released it through the
        // fulfiller; a gateway keeps it held until the bank answers.
        if (! in_array($method, $this->methods->gatewayKeys(), true)) {
            $this->releaseHold($payable);
        }

        return new CheckoutOutcome($payment, $start, $outcome);
    }

    private function releaseHold(Model $payable): void
    {
        if ($payable instanceof Booking || $payable instanceof EventAttendee) {
            $this->holds->release($payable->refresh());
        }
    }

    /**
     * The coupon to apply, if any. A code the record already carries is
     * recognised by its text before anything is looked up: among the codes
     * in force, one switched off since it was applied would read as not valid.
     */
    private function couponToApply(Model $payable, ?string $couponCode): ?Coupon
    {
        if ($couponCode === null) {
            return null;
        }

        $carried = $this->ledger->forRedeemable($payable);

        if ($carried !== null && Coupon::normalizeCode($couponCode) === $carried->coupon->code) {
            return null;
        }

        return $this->coupons->resolve($couponCode);
    }
}
