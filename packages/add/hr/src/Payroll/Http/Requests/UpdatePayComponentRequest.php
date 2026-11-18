<?php

namespace Add\Hr\Payroll\Http\Requests;

use Add\Hr\Payroll\Calculation\CalculatorRegistry;
use Add\Hr\Payroll\Enums\PayComponentKind;
use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Payslips already issued keep the component as it was.
 */
class UpdatePayComponentRequest extends FormRequest
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
            'code' => ['sometimes', 'string', 'alpha_dash', 'max:32', Rule::unique('hr_pay_components', 'code')->ignore($this->route('payComponent'))],
            ...TranslatableField::sometimes('name'),
            'kind' => ['sometimes', Rule::enum(PayComponentKind::class)],
            'calculator' => ['sometimes', 'string', Rule::in(array_keys(app(CalculatorRegistry::class)->all()))],
            'config' => ['nullable', 'array'],
            'applies_to_all' => ['sometimes', 'boolean'],
            'taxable' => ['sometimes', 'boolean'],
            'insurable' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:65000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
