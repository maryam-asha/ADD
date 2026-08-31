<?php

namespace App\Http\Requests\Admin;

use App\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `is_base` is deliberately not a field here at all — a brand-new currency
 * starting as base makes no sense; use PATCH currencies/{currency}/base to
 * reassign the base after creation (docs/decisions/multi-currency-support.md).
 */
class StoreCurrencyRequest extends FormRequest
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
                'code' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/', 'unique:currencies,code'],
                'symbol' => ['nullable', 'string', 'max:10'],
                'decimal_places' => ['required', 'integer', 'min:0', 'max:6'],
                'is_active' => ['nullable', 'boolean'],
                'order' => ['nullable', 'integer', 'min:0'],
            ]
        );
    }
}
