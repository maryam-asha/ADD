<?php

namespace Add\Hr\Timesheets\Http\Requests;

use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCostCenterRequest extends FormRequest
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
            'code' => ['sometimes', 'string', 'alpha_dash', 'max:32', Rule::unique('hr_cost_centers', 'code')->ignore($this->route('costCenter'))],
            ...TranslatableField::sometimes('name'),
            'costable_type' => ['nullable', 'required_with:costable_id', 'string', 'max:64'],
            'costable_id' => ['nullable', 'required_with:costable_type', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
