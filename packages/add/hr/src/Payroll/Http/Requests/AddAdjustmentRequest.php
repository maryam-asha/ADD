<?php

namespace Add\Hr\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddAdjustmentRequest extends FormRequest
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
            'employee_id' => ['required', 'integer', Rule::exists('hr_employees', 'id')],
            'pay_component_id' => ['required', 'integer', Rule::exists('hr_pay_components', 'id')],
            'amount' => ['required', 'numeric', 'not_in:0', 'between:-9999999999,9999999999'],
            'currency' => ['required', 'string', 'size:3'],
            'note' => ['required', 'string', 'max:500'],
        ];
    }
}
