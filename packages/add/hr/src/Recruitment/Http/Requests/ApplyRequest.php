<?php

namespace Add\Hr\Recruitment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An application from the public careers page: consent to processing the data is required.
 */
class ApplyRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'cover_letter' => ['nullable', 'string', 'max:5000'],
            'cv' => ['required', 'file', 'mimes:'.implode(',', (array) config('hr.recruitment.cv_mimes', ['pdf', 'doc', 'docx'])), 'max:'.(int) config('hr.recruitment.cv_max_kb', 5120)],
            'consent' => ['accepted'],
        ];
    }
}
