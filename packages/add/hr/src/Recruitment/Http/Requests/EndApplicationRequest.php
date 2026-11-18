<?php

namespace Add\Hr\Recruitment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rejecting an application, or recording that the candidate withdrew.
 */
class EndApplicationRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
