<?php

namespace Add\Hr\Training\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Asking to spend my learning budget, in the budget's currency
 * (hr.training.currency).
 */
class LearningRequestRequest extends FormRequest
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
            'course_id' => ['nullable', 'integer', Rule::exists('hr_courses', 'id')],
            'title' => ['required', 'string', 'max:255'],
            'provider' => ['nullable', 'string', 'max:255'],
            'starts_on' => ['nullable', 'date'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'justification' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
