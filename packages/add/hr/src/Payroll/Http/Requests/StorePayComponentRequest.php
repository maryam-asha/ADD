<?php

namespace Add\Hr\Payroll\Http\Requests;

use Add\Hr\Payroll\Calculation\CalculatorRegistry;
use Add\Hr\Payroll\Enums\PayComponentKind;
use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `config` is the calculator's: prorate (fixed); components, percent
 * (percent_of); table, base, components, less (rate_table); base_components,
 * hours_per_month, multiplier, multiplier_table (overtime); base_components,
 * days_per_month (unpaid_days); in_off_cycle for components that also run
 * in off-cycle payrolls.
 */
class StorePayComponentRequest extends FormRequest
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
            'code' => ['required', 'string', 'alpha_dash', 'max:32', Rule::unique('hr_pay_components', 'code')],
            ...TranslatableField::rules('name'),
            'kind' => ['required', Rule::enum(PayComponentKind::class)],
            'calculator' => ['required', 'string', Rule::in(array_keys(app(CalculatorRegistry::class)->all()))],
            'config' => ['nullable', 'array'],
            'applies_to_all' => ['sometimes', 'boolean'],
            'taxable' => ['sometimes', 'boolean'],
            'insurable' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:65000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
