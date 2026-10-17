<?php

namespace Add\Hr\Benefits\Http\Requests;

use Add\Hr\Benefits\Enums\BenefitFrequency;
use Add\Hr\Benefits\Enums\BenefitKind;
use Add\Hr\Payroll\Enums\PayComponentKind;
use Add\Hr\Payroll\Models\PayComponent;
use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Money kinds (cash, entitlement) carry a currency, an in-kind credit a
 * unit and the host's `fulfillment` options. A cash allowance is paid
 * through an earning component using the benefit_allowance calculator and
 * applying to everyone; its cost is its payslip line, so it takes no
 * employer_cost.
 */
class StoreBenefitRequest extends FormRequest
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
        $cash = BenefitKind::CashAllowance->value;
        $inKind = BenefitKind::InKindCredit->value;

        $rules = [
            'code' => ['required', 'string', 'alpha_dash', 'max:32', Rule::unique('hr_benefits', 'code')],
            ...TranslatableField::rules('name'),
            ...TranslatableField::rules('description', false),
            'kind' => ['required', Rule::enum(BenefitKind::class)],
            'frequency' => ['required', Rule::enum(BenefitFrequency::class)],
            'grant_month' => ['nullable', 'integer', 'between:1,12', 'prohibited_unless:frequency,'.BenefitFrequency::Yearly->value],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'currency' => ["required_unless:kind,{$inKind}", "prohibited_if:kind,{$inKind}", 'string', 'size:3'],
            'unit' => ["required_if:kind,{$inKind}", "prohibited_unless:kind,{$inKind}", 'string', 'alpha_dash', 'max:16'],
            'employer_cost' => ["prohibited_if:kind,{$cash}", 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'cost_currency' => ['required_with:employer_cost', 'nullable', 'string', 'size:3'],
            'pay_component_id' => ["required_if:kind,{$cash}", "prohibited_unless:kind,{$cash}", 'integer', Rule::exists('hr_pay_components', 'id')],
            'fulfillment' => ["prohibited_unless:kind,{$inKind}", 'nullable', 'array'],
            'eligible_after_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        return $this->input('kind') === $inKind ? FulfillerRules::merge($rules) : $rules;
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $id = $this->input('pay_component_id');

            if ($id === null || $validator->errors()->has('pay_component_id')) {
                return;
            }

            $component = PayComponent::query()->find($id);

            if ($component->kind !== PayComponentKind::Earning
                || $component->calculator !== 'benefit_allowance'
                || ! $component->applies_to_all) {
                $validator->errors()->add('pay_component_id', __('hr::benefits.component_not_allowance', [
                    'calculator' => 'benefit_allowance',
                ]));
            }
        }];
    }
}
