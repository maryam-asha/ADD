<?php

namespace Add\Hr\Leave\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A rule covers either a unit or a position, never both.
 */
class StoreCoverageRuleRequest extends FormRequest
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
            'org_unit_id' => ['required_without:position_id', 'prohibits:position_id', 'nullable', 'integer', Rule::exists('hr_org_units', 'id')],
            'position_id' => ['required_without:org_unit_id', 'nullable', 'integer', Rule::exists('hr_positions', 'id')],
            'min_present' => ['required', 'integer', 'min:1', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
