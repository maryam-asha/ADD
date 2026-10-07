<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Contracts\PaymentGateway;
use App\Domain\Finance\Payments\Methods\PaymeraGateway;
use App\Domain\Finance\Payments\PaymentMethodRegistry;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Services\CouponLedger;
use App\Domain\Pricing\Services\PriceQuoteService;
use App\Domain\Pricing\Services\QuoteRecorder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Opens a card payment and returns the hosted page to send the member to.
 *
 * The money rules this enforces:
 *
 *  - **The charged amount is computed here, never accepted from the
 *    client.** For a plan purchase it comes from `plans.price_points`; for
 *    a top-up the member names a currency amount, which is validated
 *    upstream and converted to points here. Either way the browser cannot
 *    influence what is charged or what is granted.
 *  - **The point count is frozen at creation.** The point rate is an
 *    admin-editable setting. If it moves while the member is entering
 *    their card, they still receive the number of points the app quoted
 *    them, because that number is stored on the payment row up front.
 *  - **Creation never retries.** PaymeraClient enforces that; a retried
 *    create is how members get charged twice.
 *
 * The row is written BEFORE Paymera is called, so a create that times out
 * after Paymera committed still leaves us a record the reconciler can
 * resolve. That row simply has no `payment_id` yet, and is failed
 * explicitly if the call does not come back. The call itself is the
 * Paymera gateway's `start()`, reached through the payment-method registry.
 */
class StartPayment
{
    public function __construct(
        private readonly PaymentMethodRegistry $registry,
        private readonly WalletService $wallets,
        private readonly PaymentAmountCalculator $amounts,
        private readonly PriceQuoteService $quotes,
        private readonly CouponLedger $couponLedger,
        private readonly QuoteRecorder $recorder,
    ) {}

    /**
     * A plan made free by its coupon has nothing to charge by card: the
     * caller settles it through PaymentService instead.
     *
     * @param  int|null  $amountMinor  required for a top-up, ignored for a plan purchase
     * @param  string  $method  the gateway's key in config/payments.php — the payment is opened through it and recorded under it
     * @return array{payment: Payment, url: string}
     */
    public function handle(
        User $user,
        PaymentPurpose $purpose,
        ?Plan $plan = null,
        ?int $amountMinor = null,
        ?string $lang = null,
        ?Coupon $coupon = null,
        string $method = PaymeraGateway::KEY,
    ): array {
        if ($purpose === PaymentPurpose::PlanPurchase && $plan === null) {
            throw new InvalidArgumentException('A plan purchase requires a plan.');
        }

        // Resolved before the row is written: a method that is switched off
        // must refuse here, not after leaving a pending row with no id.
        $gateway = $this->registry->get($method);

        if (! $gateway instanceof PaymentGateway) {
            throw new InvalidArgumentException("[{$method}] is not a gateway: StartPayment opens card payments only.");
        }

        // A member always funds their own personal wallet here. Company
        // wallets are funded by their admins through the existing
        // allocation/top-up paths, which carry their own authorization.
        $wallet = $this->wallets->walletFor(OwnerType::User, $user->id);

        $payment = DB::transaction(function () use ($user, $purpose, $plan, $amountMinor, $lang, $coupon, $wallet, $method) {
            // The coupon's row first (CouponLedger), then the price it gives.
            $coupon = $this->couponLedger->lockForPricing($coupon);
            $quote = $purpose === PaymentPurpose::PlanPurchase ? $this->quotes->forPlan($plan, $user, $coupon, now()) : null;

            // Points are the unit of account, so both purposes resolve to the
            // same pair: how many points are granted, and what that costs in
            // the gateway's minor units. A plan's price is PriceQuoteService's
            // — its sale and coupon included — and the charge is derived from
            // those points, as for anything else.
            [$points, $chargeMinor] = $quote !== null
                ? $this->amounts->forPoints($quote->finalPoints)
                : $this->amounts->forTopUp($amountMinor);

            $payment = Payment::create([
                'callback_token' => Payment::newCallbackToken(),
                'method' => $method,
                'purpose' => $purpose,
                'status' => PaymentStatus::Pending,
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'plan_id' => $plan?->id,
                'amount_minor' => $chargeMinor,
                'currency' => (string) config('paymera.currency'),
                'currency_exponent' => (int) config('paymera.currency_exponent'),
                'points_to_credit' => $points,
                'amount_points' => $points,
                'list_price_points' => $plan !== null ? (int) $plan->price_points : null,
                'terminal_id' => (string) config('paymera.terminal_id'),
                'lang' => $lang ?? (string) config('paymera.default_lang'),
            ]);

            if ($quote !== null) {
                $this->recorder->record($quote, $payment, $user, PricingPurpose::PlanPurchase);
            }

            return $payment;
        });

        // The gateway creates the payment, records its id on the row, or
        // marks the row Failed and rethrows if the call does not come back.
        $start = $gateway->start($payment);

        return ['payment' => $payment, 'url' => (string) $start->redirectUrl];
    }
}
