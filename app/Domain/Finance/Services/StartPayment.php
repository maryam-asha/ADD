<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Methods\PaymeraGateway;
use App\Domain\Finance\Payments\PaymentMethodRegistry;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Services\WalletService;
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
    ) {}

    /**
     * @param  int|null  $amountMinor  required for a top-up, ignored for a plan purchase
     * @return array{payment: Payment, url: string}
     */
    public function handle(
        User $user,
        PaymentPurpose $purpose,
        ?Plan $plan = null,
        ?int $amountMinor = null,
        ?string $lang = null,
    ): array {
        if ($purpose === PaymentPurpose::PlanPurchase && $plan === null) {
            throw new InvalidArgumentException('A plan purchase requires a plan.');
        }

        // Points are the unit of account, so both purposes resolve to the
        // same pair: how many points are granted, and what that costs in
        // the gateway's minor units.
        [$points, $chargeMinor] = $purpose === PaymentPurpose::PlanPurchase
            ? $this->amounts->forPlan($plan)
            : $this->amounts->forTopUp($amountMinor);

        // Resolved before the row is written: a method that is switched off
        // must refuse here, not after leaving a pending row with no id.
        $gateway = $this->registry->get(PaymeraGateway::KEY);

        // A member always funds their own personal wallet here. Company
        // wallets are funded by their admins through the existing
        // allocation/top-up paths, which carry their own authorization.
        $wallet = $this->wallets->walletFor(OwnerType::User, $user->id);

        $payment = DB::transaction(fn () => Payment::create([
            'callback_token' => Payment::newCallbackToken(),
            'method' => PaymeraGateway::KEY,
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
            'terminal_id' => (string) config('paymera.terminal_id'),
            'lang' => $lang ?? (string) config('paymera.default_lang'),
        ]));

        // The gateway creates the payment, records its id on the row, or
        // marks the row Failed and rethrows if the call does not come back.
        $start = $gateway->start($payment);

        return ['payment' => $payment, 'url' => (string) $start->redirectUrl];
    }
}
