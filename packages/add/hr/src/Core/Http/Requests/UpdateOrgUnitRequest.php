<?php

namespace Add\Hr\Core\Http\Requests;

use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrgUnitRequest extends FormRequest
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
            ...TranslatableField::sometimes('name'),
            'code' => ['nullable', 'string', 'max:32', Rule::unique('hr_org_units', 'code')->ignore($this->route('orgUnit'))],
            'parent_id' => ['nullable', 'integer', 'exists:hr_org_units,id'],
            'head_employee_id' => ['nullable', 'integer', 'exists:hr_employees,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
