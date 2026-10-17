<?php

namespace Add\Hr\Recruitment\Http\Requests;

use Add\Hr\Recruitment\Enums\Recommendation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ratings are 1-5 by criterion, the criteria the interviewer's own.
 */
class ScorecardRequest extends FormRequest
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
            'recommendation' => ['required', Rule::enum(Recommendation::class)],
            'ratings' => ['nullable', 'array', 'max:20'],
            'ratings.*' => ['integer', 'between:1,5'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
