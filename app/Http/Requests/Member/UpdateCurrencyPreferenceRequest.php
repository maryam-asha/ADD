<?php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Must be an active, admin-managed currency (`currencies.is_active`), not
 * a hardcoded enum (docs/decisions/multi-currency-support.md) — rejected
 * outright rather than silently accepted with no conversion path.
 */
class UpdateCurrencyPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preferred_currency' => [
                'required',
                Rule::exists('currencies', 'code')->where('is_active', true),
            ],
        ];
    }
}
