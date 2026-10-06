<?php

namespace App\Http\Requests\Member;

use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\PaymentMethodRegistry;
use App\Domain\Finance\Services\PaymentAmountCalculator;
use App\Rules\AmountFitsCurrency;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A member topping up their own wallet from the app — by a prepaid method
 * only; cash for points is reception's. `amount` is what they want to add,
 * in the gateway currency's major units.
 */
class StoreTopUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Omitted: the card. Only methods that can top up (cash cannot,
            // nor can points buy points).
            'payment_method' => ['nullable', 'string', Rule::in(array_keys(
                app(PaymentMethodRegistry::class)->availableFor(PaymentPurpose::WalletTopUp)
            ))],
            // A plain decimal: `numeric` alone lets `1e3` through, which
            // bcmath refuses (amountMinor()).
            'amount' => [
                'required',
                'numeric',
                'regex:/^\d+(\.\d{1,6})?$/',
                'min:0.01',
                new AmountFitsCurrency((string) config('paymera.currency')),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // A top-up has a floor, named in money as well as points, compared
            // in money. PaymentPricingException (no exchange rate) is not
            // caught: it is ours, rendered as a 503 (bootstrap/app.php).
            $minimum = app(PaymentAmountCalculator::class)->minimumTopUp();

            if ((int) $this->amountMinor() < $minimum['amount_minor']) {
                $validator->errors()->add('amount', __('api.wallet.top_up_below_minimum', [
                    'amount' => number_format((int) $minimum['amount']),
                    'currency' => $minimum['currency'],
                    'points' => $minimum['points'],
                ]));
            }
        });
    }

    /**
     * The amount in the gateway's integer minor units, with bcmath — never
     * (int) round($x * 100), which turns 10.10 into 1009.
     */
    public function amountMinor(): ?int
    {
        $amount = $this->input('amount');

        if ($amount === null) {
            return null;
        }

        return (int) bcmul((string) $amount, bcpow('10', (string) config('paymera.currency_exponent'), 0), 0);
    }
}
