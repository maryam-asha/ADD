<?php

namespace App\Http\Requests\Admin;

use App\Domain\Finance\Models\Currency;
use App\Rules\CashStepFitsDecimals;
use App\Support\TranslatableField;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `code`, `is_base` and `is_active` are deliberately absent — the code is
 * the immutable primary key, `is_base` is reassignable only via the dedicated
 * PATCH currencies/{currency}/base endpoint, and `is_active` is a separate
 * action (see UpdateCurrencyStatusRequest).
 */
class UpdateCurrencyRequest extends FormRequest
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
        return array_merge(
            TranslatableField::rules('name'),
            [
                'symbol' => ['nullable', 'string', 'max:10'],
                'decimal_places' => ['required', 'integer', 'min:0', 'max:6'],
                'cash_step' => ['sometimes', 'numeric', 'gt:0', 'decimal:0,2', new CashStepFitsDecimals($this->input('decimal_places'))],
                'order' => ['nullable', 'integer', 'min:0'],
            ]
        );
    }

    /**
     * Without a new step, the stored one must still fit the decimals being
     * set — dropping a currency to 0 decimals under a 0.50 step would leave
     * the dashboard quoting amounts no top-up accepts.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $currency = $this->route('currency');

            if ($this->has('cash_step') || $validator->errors()->has('decimal_places') || ! $currency instanceof Currency) {
                return;
            }

            (new CashStepFitsDecimals($this->input('decimal_places')))->validate(
                'cash_step',
                $currency->cash_step,
                fn (string $message) => $validator->errors()->add('cash_step', $message),
            );
        });
    }
}
