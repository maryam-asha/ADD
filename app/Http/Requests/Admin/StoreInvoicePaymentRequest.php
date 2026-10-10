<?php

namespace App\Http\Requests\Admin;

use App\Domain\Invoicing\Enums\InvoicePaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoicePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
