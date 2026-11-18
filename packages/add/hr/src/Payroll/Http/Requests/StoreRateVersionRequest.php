<?php

namespace Add\Hr\Payroll\Http\Requests;

use Add\Hr\Hr;
use Add\Hr\Payroll\Enums\RateTableKind;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A percent table takes `rate` (and an optional `max_base` ceiling);
 * a brackets table takes ascending `brackets` [{up_to, rate}], the last
 * with up_to null. Amounts are in `currency` (default: the run's).
 */
class StoreRateVersionRequest extends FormRequest
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
        $brackets = $this->route('rateTable')?->kind === RateTableKind::Brackets;

        return [
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'rate' => [$brackets ? 'prohibited' : 'required', 'numeric', 'min:0', 'max:1000'],
            'max_base' => [$brackets ? 'prohibited' : 'nullable', 'numeric', 'gt:0'],
            'brackets' => [$brackets ? 'required' : 'prohibited', 'array', 'min:1', 'max:30'],
            'brackets.*.up_to' => ['nullable', 'numeric', 'gt:0'],
            'brackets.*.rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'currency' => ['nullable', ...Hr::currencyRules()],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
