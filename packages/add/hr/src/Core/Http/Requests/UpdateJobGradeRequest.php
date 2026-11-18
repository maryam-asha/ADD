<?php

namespace Add\Hr\Core\Http\Requests;

use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateJobGradeRequest extends FormRequest
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
            'code' => ['sometimes', 'string', 'max:32', Rule::unique('hr_job_grades', 'code')->ignore($this->route('jobGrade'))],
            'rank' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
