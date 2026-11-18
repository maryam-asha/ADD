<?php

namespace App\Http\Requests\Admin;

use App\Domain\Finance\Services\CurrencyConversionService;
use App\Domain\Invoicing\Services\InvoiceMoney;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ApproveProviderRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['invoice_amount', 'invoice_currency']) || ! $this->filled('invoice_amount')) {
                return;
            }

            $money = app(InvoiceMoney::class);
            $currency = $this->input('invoice_currency') ?? app(CurrencyConversionService::class)->baseCurrencyCode();

            if ($money->exceedsDecimals((string) $this->input('invoice_amount'), $currency)) {
                $validator->errors()->add('invoice_amount', __('api.invoice.too_many_decimals', ['places' => $money->decimals($currency)]));
            }
        }];
    }

    public function rules(): array
    {
        return [
            // Sell past a full pool, by arrangement — recorded in the audit log.
            'override_capacity' => ['sometimes', 'boolean'],
            // The invoice an assignment or a top-up issues (provider invoicing):
            // each optional, the default used when absent.
            'invoice_currency' => ['sometimes', 'string', 'size:3', 'exists:currencies,code'],
            'invoice_amount' => ['sometimes', 'decimal:0,2', 'gt:0'],
            'invoice_due_at' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }
}
