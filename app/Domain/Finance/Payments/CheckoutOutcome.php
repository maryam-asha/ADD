<?php

namespace App\Domain\Finance\Payments;

use App\Domain\Finance\Models\Payment;

/**
 * What paying an existing record came to: the payment row (null for cash,
 * which writes none, or when a coupon left nothing to pay), what the app
 * does next, and — when a code was sent — what it took off
 * (CouponOutcomePresenter).
 */
final readonly class CheckoutOutcome
{
    /**
     * @param  array<string, mixed>|null  $coupon
     */
    public function __construct(
        public ?Payment $payment,
        public PaymentStart $start,
        public ?array $coupon = null,
    ) {}
}
