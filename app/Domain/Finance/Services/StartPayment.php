<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
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
 * explicitly if the call does not come back.
 */
class StartPayment
{
    public function __construct(
        private readonly PaymeraClient $client,
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

        // A member always funds their own personal wallet here. Company
        // wallets are funded by their admins through the existing
        // allocation/top-up paths, which carry their own authorization.
        $wallet = $this->wallets->walletFor(OwnerType::User, $user->id);

        $payment = DB::transaction(fn () => Payment::create([
            'callback_token' => Payment::newCallbackToken(),
            'purpose' => $purpose,
            'status' => PaymentStatus::Pending,
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'plan_id' => $plan?->id,
            'amount_minor' => $chargeMinor,
            'currency' => (string) config('paymera.currency'),
            'currency_exponent' => (int) config('paymera.currency_exponent'),
            'points_to_credit' => $points,
            'terminal_id' => (string) config('paymera.terminal_id'),
            'lang' => $lang ?? (string) config('paymera.default_lang'),
        ]));

        try {
            $created = $this->client->createPayment(
                amountMinor: $chargeMinor,
                callbackUrl: route('paymera.callback', ['token' => $payment->callback_token]),
                triggerUrl: route('paymera.trigger', ['token' => $payment->callback_token]),
                lang: $payment->lang,
                extra: $this->savedCardOptions($user),
            );
        } catch (\Throwable $e) {
            // The local row must not linger as Pending: the reconciler would
            // keep polling a payment id that does not exist. It is marked
            // failed here, and never retried automatically.
            $payment->forceFill([
                'status' => PaymentStatus::Failed,
                'review_reason' => 'create-payment failed: '.$e->getMessage(),
            ])->save();

            throw $e;
        }

        $payment->forceFill([
            'payment_id' => $created->paymentId,
        ])->save();

        return ['payment' => $payment, 'url' => $created->url];
    }

    /**
     * `appUser` is mandatory whenever savedCards is on — it is the identity
     * Paymera files the stored card under. Sending our own user id keeps
     * one member's saved cards from ever surfacing for another.
     *
     * @return array<string, mixed>
     */
    private function savedCardOptions(User $user): array
    {
        if (! config('paymera.saved_cards_enabled')) {
            return [];
        }

        return [
            'savedCards' => 1,
            'appUser' => (string) $user->id,
        ];
    }
}
