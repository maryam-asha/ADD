<?php

namespace App\Http\Requests\Member;

use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\Methods\PaymeraGateway;
use App\Domain\Finance\Payments\PaymentMethodRegistry;
use App\Domain\Finance\Services\PaymentAmountCalculator;
use App\Domain\Membership\Models\Plan;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Opening a card payment.
 *
 * `amount` here is what the member wants to ADD to their wallet, in the
 * charged currency's major units — it is never what gets charged for a
 * plan. For a plan purchase the price comes from the database and any
 * `amount` in the request is ignored outright (see StartPayment), which is
 * the rule that stops a client from naming its own price.
 */
class StartPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @var list<string>
     */
    private const PURPOSES = ['wallet_top_up', 'plan_purchase', 'booking', 'coworking_session', 'event_registration'];

    /**
     * @var list<string>
     */
    private const PAYABLE_PURPOSES = ['booking', 'coworking_session', 'event_registration'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Everything a member pays for from the app. An extension is
            // only ever paid as it happens, never from here.
            'purpose' => [
                'bail',
                'required',
                Rule::enum(PaymentPurpose::class),
                Rule::in(self::PURPOSES),
            ],

            // Omitted: a card — what this endpoint always opened.
            'method' => ['nullable', 'string', Rule::in(array_keys(app(PaymentMethodRegistry::class)->enabled()))],

            // What is being paid for, for a purpose that pays for a record.
            'payable_id' => [
                'required_if:purpose,'.implode(',', self::PAYABLE_PURPOSES),
                'nullable',
                'integer',
            ],

            'wallet_owner_type' => ['nullable', 'string', Rule::in(['user', 'company']), 'required_with:wallet_owner_id'],
            'wallet_owner_id' => ['nullable', 'integer', 'required_with:wallet_owner_type'],

            // Required for a top-up, meaningless for a plan purchase. A plain
            // decimal: `numeric` alone lets `1e3` through, which bcmath
            // refuses (amountMinor()).
            'amount' => [
                'required_if:purpose,'.PaymentPurpose::WalletTopUp->value,
                'numeric',
                'regex:/^\d+(\.\d{1,6})?$/',
                'min:0.01',
            ],

            'plan_id' => [
                'required_if:purpose,'.PaymentPurpose::PlanPurchase->value,
                'integer',
                'exists:plans,id',
            ],

            'lang' => ['nullable', 'string', 'in:en,ar'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            match (PaymentPurpose::from($this->input('purpose'))) {
                PaymentPurpose::PlanPurchase => $this->validatePlan($validator),
                PaymentPurpose::WalletTopUp => $this->validateTopUpAmount($validator),
                default => null,
            };
        });
    }

    /**
     * A plan the member cannot buy must be refused before a card is
     * charged, not after. `publiclyListable` is the same filter the catalog
     * uses, so a member can only pay for something they were actually
     * offered — a private or deactivated plan id guessed by hand is
     * rejected here.
     *
     * A one-time package is not a subscription, and no membership can be
     * made of one — the rule POST member/memberships applies
     * (StoreMembershipRequest). Refused here rather than after the money is
     * taken.
     */
    private function validatePlan(Validator $validator): void
    {
        $plan = Plan::query()->publiclyListable()->find($this->input('plan_id'));

        if ($plan === null || ! $plan->is_subscription) {
            $validator->errors()->add('plan_id', __('api.payment.plan_unavailable'));

            return;
        }

        if ((int) $plan->price_points <= 0) {
            $validator->errors()->add('plan_id', __('api.payment.plan_unavailable'));
        }
    }

    /**
     * The same "worth less than one point" refusal the reception till
     * applies. Credits floor, so an amount below one point's worth would
     * take the member's money and credit nothing.
     */
    private function validateTopUpAmount(Validator $validator): void
    {
        $amounts = app(PaymentAmountCalculator::class);

        try {
            $amounts->forTopUp($this->amountMinor());
        } catch (\InvalidArgumentException) {
            $validator->errors()->add('amount', __('api.wallet.amount_below_one_point'));
        }

        // PaymentPricingException is deliberately NOT caught here. It means
        // the charge currency has no exchange rate — a misconfiguration on
        // our side, not something wrong with the amount the member typed.
        // Reporting it as a validation error on `amount` would tell them to
        // fix input that is perfectly valid. It propagates instead, and
        // bootstrap/app.php renders it as a 503.
    }

    /**
     * The requested amount in the gateway's integer minor units. Computed
     * with bcmath, never (int) round($x * 100): a float multiplication here
     * is how a request for 10.10 becomes 1009 minor units.
     */
    public function amountMinor(): ?int
    {
        $amount = $this->input('amount');

        if ($amount === null) {
            return null;
        }

        $factor = bcpow('10', (string) config('paymera.currency_exponent'), 0);

        return (int) bcmul((string) $amount, $factor, 0);
    }

    public function paymentMethod(): string
    {
        return $this->validated('method') ?? PaymeraGateway::KEY;
    }

    public function purpose(): PaymentPurpose
    {
        return PaymentPurpose::from($this->validated('purpose'));
    }
}
