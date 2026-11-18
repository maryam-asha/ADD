<?php

namespace Add\Hr\Timesheets\Http\Requests;

use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `costable_type` / `costable_id` optionally link a host record (a morph
 * alias the host knows, e.g. a project).
 */
class StoreCostCenterRequest extends FormRequest
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
            'code' => ['required', 'string', 'alpha_dash', 'max:32', Rule::unique('hr_cost_centers', 'code')],
            ...TranslatableField::rules('name'),
            'costable_type' => ['nullable', 'required_with:costable_id', 'string', 'max:64'],
            'costable_id' => ['nullable', 'required_with:costable_type', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
