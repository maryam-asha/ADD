<?php

namespace App\Http\Requests\Admin;

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
        ];
    }
}
