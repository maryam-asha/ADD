<?php

namespace Add\Hr\Benefits\Http\Requests;

use Add\Hr\Benefits\Enums\BenefitFrequency;
use Add\Hr\Benefits\Enums\BenefitKind;
use Add\Hr\Benefits\Models\Benefit;
use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What a grant is worth from now on. Kind, frequency, currency or unit and
 * pay component stay as created: grants already made were made under them.
 * Grants keep the values they were made with.
 */
class UpdateBenefitRequest extends FormRequest
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
        /** @var Benefit $benefit */
        $benefit = $this->route('benefit');
        $inKind = $benefit->kind === BenefitKind::InKindCredit;

        $rules = [
            ...TranslatableField::sometimes('name'),
            ...TranslatableField::sometimes('description'),
            'grant_month' => [$benefit->frequency === BenefitFrequency::Yearly ? 'sometimes' : 'prohibited', 'nullable', 'integer', 'between:1,12'],
            'amount' => ['sometimes', 'numeric', 'gt:0', 'max:9999999999'],
            'employer_cost' => [$benefit->kind === BenefitKind::CashAllowance ? 'prohibited' : 'sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'cost_currency' => ['required_with:employer_cost', 'nullable', 'string', 'size:3'],
            'fulfillment' => [$inKind ? 'sometimes' : 'prohibited', 'nullable', 'array'],
            'eligible_after_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        return $inKind ? FulfillerRules::merge($rules) : $rules;
    }
}
