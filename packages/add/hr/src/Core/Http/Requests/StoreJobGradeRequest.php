<?php

namespace Add\Hr\Core\Http\Requests;

use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;

class StoreJobGradeRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:32', 'unique:hr_job_grades,code'],
            'rank' => ['required', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
