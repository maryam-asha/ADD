<?php

namespace App\Http\Requests\Admin;

use App\Domain\Invoicing\Enums\InvoicePaymentMethod;
use App\Domain\Invoicing\Services\InvoiceMoney;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreInvoicePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['amount', 'currency'])) {
                return;
            }

            $money = app(InvoiceMoney::class);
            $currency = (string) $this->input('currency');

            if ($money->exceedsDecimals((string) $this->input('amount'), $currency)) {
                $validator->errors()->add('amount', __('api.invoice.too_many_decimals', ['places' => $money->decimals($currency)]));
            }
        }];
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'currency' => ['required', 'string', 'size:3', 'exists:currencies,code'],
            'method' => ['required', Rule::enum(InvoicePaymentMethod::class)],
            'receipt_number' => ['nullable', 'string', 'max:100'],
            'paid_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }
}
