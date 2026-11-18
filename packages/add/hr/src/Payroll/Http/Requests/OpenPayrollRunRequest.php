<?php

namespace Add\Hr\Payroll\Http\Requests;

use Add\Hr\Hr;
use Add\Hr\Payroll\Enums\PayrollRunKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OpenPayrollRunRequest extends FormRequest
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
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'kind' => ['required', Rule::enum(PayrollRunKind::class)],
            'currency' => ['nullable', ...Hr::currencyRules()],
            'parallel_run' => ['sometimes', 'boolean'],
            'employee_ids' => ['nullable', 'array', 'max:500'],
            'employee_ids.*' => ['integer', Rule::exists('hr_employees', 'id')],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
