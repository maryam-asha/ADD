<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ApproveProviderRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
