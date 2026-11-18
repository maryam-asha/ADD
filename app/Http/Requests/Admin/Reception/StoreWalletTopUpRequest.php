<?php

namespace App\Http\Requests\Admin\Reception;

use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Services\CurrencyConversionService;
use App\Domain\Finance\Services\ReceivedMoneyConverter;
use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Membership\Services\TopUpMinimum;
use App\Rules\AmountFitsCurrency;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The dashboard converts between points and cash itself and sends both
 * (docs/superpowers/specs/2026-10-10-wallet-top-up-points-entry-design.md
 * §3). This is where that client arithmetic is verified: `points` must be
 * exactly what `amount` buys at today's rates — judged by
 * ReceivedMoneyConverter, the one place money becomes points, which floors
 * once on the exact value. A mismatch — usually an exchange rate that moved
 * after the form read it — is refused, so the operator is never told one
 * number while the wallet receives another.
 */
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
            // At least the configured minimum, itself never below 1, so a
            // payment too small to credit a single point cannot get through.
            'points' => ['required', 'integer', 'min:'.app(TopUpMinimum::class)->points()],
            // A plain decimal: `numeric` alone lets `1e3` through, which
            // bcmath refuses (docs/decisions/unified-payments.md).
            'amount' => ['required', 'numeric', 'regex:/^\d+(\.\d{1,6})?$/', 'min:0.01', new AmountFitsCurrency($this->input('currency'))],
            'currency' => ['nullable', 'string', 'exists:currencies,code'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)->only(PaymentMethod::atReception())],
            'user_id' => ['required_without:company_id', 'prohibits:company_id', 'integer', 'exists:users,id'],
            'company_id' => ['required_without:user_id', 'integer', 'exists:companies,id'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'points.min' => __('api.wallet.min_points', ['min' => app(TopUpMinimum::class)->points()]),
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

            if ($validator->errors()->hasAny(['points', 'amount', 'currency'])) {
                return;
            }

            // The same converter the controller credits with, so the figure
            // verified here is the figure credited.
            $currency = $this->input('currency') ?? app(CurrencyConversionService::class)->baseCurrencyCode();
            $buys = app(ReceivedMoneyConverter::class)->toPoints((string) $this->input('amount'), $currency)->points;

            if ($buys !== (int) $this->input('points')) {
                $validator->errors()->add('points', __('api.wallet.quote_stale'));
            }
        });
    }
}
