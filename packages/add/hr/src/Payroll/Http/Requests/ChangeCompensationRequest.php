<?php

namespace Add\Hr\Payroll\Http\Requests;

use Add\Hr\Hr;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The employee's whole pay from a date; an empty list stops it.
 */
class ChangeCompensationRequest extends FormRequest
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
        return [
            'effective_from' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:32'],
            'lines' => ['present', 'array', 'max:30'],
            'lines.*.pay_component_id' => ['required', 'integer', 'distinct', Rule::exists('hr_pay_components', 'id')->where('is_active', true)],
            'lines.*.amount' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'lines.*.currency' => ['required', ...Hr::currencyRules()],
        ];
    }
}
