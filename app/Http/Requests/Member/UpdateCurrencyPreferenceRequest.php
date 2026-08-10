<?php

namespace App\Http\Requests\Member;

use App\Domain\Finance\Enums\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * USD/SYP only — the only pair `exchange_rates` models (Unit 1 design,
 * 2026-08-09). Rejected outright rather than silently accepted with no
 * conversion path.
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
            'preferred_currency' => ['required', Rule::enum(Currency::class)],
        ];
    }
}
