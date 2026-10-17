<?php

namespace Add\Hr\Benefits\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An assignment is never rewritten: it is ended, and a new one added.
 */
class EndBenefitAssignmentRequest extends FormRequest
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
            'effective_to' => ['required', 'date'],
        ];
    }
}
