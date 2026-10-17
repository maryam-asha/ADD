<?php

namespace Add\Hr\Core\Http\Requests;

use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePositionRequest extends FormRequest
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
            ...TranslatableField::sometimes('title'),
            'org_unit_id' => ['sometimes', 'integer', 'exists:hr_org_units,id'],
            'job_grade_id' => ['nullable', 'integer', 'exists:hr_job_grades,id'],
            'code' => ['nullable', 'string', 'max:32', Rule::unique('hr_positions', 'code')->ignore($this->route('position'))],
            'headcount' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
