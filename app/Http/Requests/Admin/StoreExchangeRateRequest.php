<?php

namespace App\Http\Requests\Admin;

use App\Domain\Finance\Models\ExchangeRateSuggestion;
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
            if (! $this->filled('suggestion_id')) {
                return;
            }

            // sp-today's suggestion is USD/SYP only — accepting it against
            // any other currency would write a nonsensical rate.
            if ($this->input('currency_code') !== 'SYP') {
                $validator->errors()->add('currency_code', 'A suggestion can only be applied to the SYP currency.');

                return;
            }

            // docs/decisions/exchange-rate-external-suggestion.md, "the
            // direction problem" — rate_usd_to_syp (SYP per 1 USD) and
            // rate_to_base (USD per 1 SYP) are reciprocals. A client that
            // forgets to invert would submit the suggestion's raw number
            // directly, off by many orders of magnitude. A generous
            // plausibility band (10x either way) catches that mistake
            // while still letting a real admin edit through.
            $suggestion = ExchangeRateSuggestion::find($this->input('suggestion_id'));

            if ($suggestion && is_numeric($this->input('rate_to_base'))) {
                $expected = 1 / (float) $suggestion->rate_usd_to_syp;
                $submitted = (float) $this->input('rate_to_base');

                if ($submitted < $expected / 10 || $submitted > $expected * 10) {
                    $validator->errors()->add(
                        'rate_to_base',
                        'This value is too far from the suggestion it is meant to accept — check it was inverted correctly (the suggestion is SYP per 1 USD; rate_to_base is USD per 1 SYP).'
                    );
                }
            }
        });
    }
}
