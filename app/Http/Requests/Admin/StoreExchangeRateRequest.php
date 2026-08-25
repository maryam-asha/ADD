<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // The base currency (USD) never gets a row here — its rate to
            // itself is definitionally 1 — so is_base is explicitly excluded
            // rather than relying on "no one would pick it".
            'currency_code' => [
                'required',
                // A closure, not chained ->where() calls: Rule::exists()'s
                // string-based rule serialization mishandles a `false`
                // boolean where value (it collapses to an empty string,
                // which then matches no row at all) — a closure applies the
                // constraint directly against the query builder instead.
                Rule::exists('currencies', 'code')->where(function ($query) {
                    $query->where('is_active', true)->where('is_base', false);
                }),
            ],
            'rate_to_base' => ['required', 'numeric', 'gt:0'],
            'effective_from' => ['required', 'date'],
            // docs/decisions/exchange-rate-external-suggestion.md — accepting
            // a suggestion is purely additive to the manual-entry flow above.
            'suggestion_id' => [
                'nullable',
                'integer',
                Rule::exists('exchange_rate_suggestions', 'id')->where('status', 'pending'),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // sp-today's suggestion is USD/SYP only — accepting it against
            // any other currency would write a nonsensical rate.
            if ($this->filled('suggestion_id') && $this->input('currency_code') !== 'SYP') {
                $validator->errors()->add('currency_code', 'A suggestion can only be applied to the SYP currency.');
            }
        });
    }
}
