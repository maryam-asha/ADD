<?php

namespace Add\Hr\Core\Http\Requests;

use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;

class StorePositionRequest extends FormRequest
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
            ...TranslatableField::rules('title'),
            'org_unit_id' => ['required', 'integer', 'exists:hr_org_units,id'],
            'job_grade_id' => ['nullable', 'integer', 'exists:hr_job_grades,id'],
            'code' => ['nullable', 'string', 'max:32', 'unique:hr_positions,code'],
            'headcount' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
