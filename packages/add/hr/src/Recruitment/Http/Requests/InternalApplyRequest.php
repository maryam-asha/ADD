<?php

namespace Add\Hr\Recruitment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An employee applying to an opening from self-service.
 */
class InternalApplyRequest extends FormRequest
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
            'cover_letter' => ['nullable', 'string', 'max:5000'],
            'cv' => ['nullable', 'file', 'mimes:'.implode(',', (array) config('hr.recruitment.cv_mimes', ['pdf', 'doc', 'docx'])), 'max:'.(int) config('hr.recruitment.cv_max_kb', 5120)],
        ];
    }
}
