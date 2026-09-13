<?php

namespace App\Http\Requests\Admin\Reception;

use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Services\CurrencyConversionService;
use App\Domain\Finance\Services\PointConversionService;
use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Models\Company;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWalletTopUpRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'exists:currencies,code'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'user_id' => ['required_without:company_id', 'prohibits:company_id', 'integer', 'exists:users,id'],
            'company_id' => ['required_without:user_id', 'integer', 'exists:companies,id'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->filled('company_id')) {
                $company = Company::find($this->input('company_id'));
                if ($company && $company->status !== CompanyStatus::Active) {
                    $validator->errors()->add('company_id', 'Cannot top up wallet for an inactive company.');
                }
            }

            if ($this->filled('currency')) {
                $currencyCode = $this->input('currency');
                $baseCode = Currency::query()->where('is_base', true)->value('code') ?? 'USD';
                if ($currencyCode !== $baseCode && ExchangeRate::current($currencyCode) === null) {
                    $validator->errors()->add('currency', "No active exchange rate found for currency {$currencyCode}.");
                }
            }

            // Credits floor (Invariant 2), so a payment worth less than one
            // whole point would credit nothing at all while still writing a
            // transaction row recording the full amount taken. Refuse it at
            // the door rather than swallow the money.
            $amount = $this->input('amount');

            if ($amount !== null && ! $validator->errors()->has('amount') && ! $validator->errors()->has('currency')) {
                $points = app(PointConversionService::class);
                $conversion = app(CurrencyConversionService::class);

                $base = $conversion->baseCurrencyCode();
                $currency = $this->input('currency') ?? $base;

                $usd = $currency === $base
                    ? (string) $amount
                    : (string) $conversion->convert((float) $amount, $currency, $base);

                if ($points->usdToPoints($usd) < 1) {
                    $validator->errors()->add('amount', __('api.wallet.amount_below_one_point'));
                }
            }
        });
    }
}
