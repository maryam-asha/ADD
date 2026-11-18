<?php

namespace Add\Hr\Training\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Scheduling (POST) or changing (PATCH, scheduled sessions only) a session.
 */
class TrainingSessionRequest extends FormRequest
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
        $presence = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            ...($this->isMethod('post') ? ['course_id' => ['required', 'integer', Rule::exists('hr_courses', 'id')->where('is_active', true)]] : []),
            'starts_on' => [$presence, 'date'],
            'ends_on' => [$presence, 'date', 'after_or_equal:starts_on'],
            'location' => ['nullable', 'string', 'max:255'],
            'trainer' => ['nullable', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'self_enrollment' => ['sometimes', 'boolean'],
        ];
    }
}
