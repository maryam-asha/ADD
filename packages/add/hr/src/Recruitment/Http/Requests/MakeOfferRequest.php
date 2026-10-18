<?php

namespace Add\Hr\Recruitment\Http\Requests;

use Add\Hr\Hr;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The pay lines become the new employee's compensation from their hire date.
 */
class MakeOfferRequest extends FormRequest
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
            'start_on' => ['required', 'date'],
            'expires_on' => ['nullable', 'date'],
            'compensation' => ['nullable', 'array', 'max:30'],
            'compensation.*.pay_component_id' => ['required', 'integer', 'distinct', Rule::exists('hr_pay_components', 'id')->where('is_active', true)],
            'compensation.*.amount' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'compensation.*.currency' => ['required', ...Hr::currencyRules()],
            'terms' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
