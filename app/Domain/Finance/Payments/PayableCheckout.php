<?php

namespace App\Domain\Finance\Payments;

use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Identity\Models\User;
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
 * id, the request it made the record with — coupon included.
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
        $coupon = $couponCode !== null ? $this->coupons->resolve($couponCode) : null;

        if ($coupon !== null && $this->ledger->forRedeemable($payable)?->coupon_id === $coupon->id) {
            $coupon = null;
        }

        $outcome = null;

        if ($coupon !== null) {
            $quote = $this->application->apply($payable, $member, $coupon);
            $outcome = $this->outcomes->fromQuote($couponCode, $quote);

            if ($quote->finalPoints === 0) {
                return new CheckoutOutcome(null, PaymentStart::done(), $outcome);
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

        return new CheckoutOutcome($payment, $start, $outcome);
    }
}
