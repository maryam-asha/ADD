<?php

namespace Add\Hr\Core\Http\Requests;

use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;

class StoreOrgUnitRequest extends FormRequest
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
            ...TranslatableField::rules('name'),
            'code' => ['nullable', 'string', 'max:32', 'unique:hr_org_units,code'],
            'parent_id' => ['nullable', 'integer', 'exists:hr_org_units,id'],
            'head_employee_id' => ['nullable', 'integer', 'exists:hr_employees,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
