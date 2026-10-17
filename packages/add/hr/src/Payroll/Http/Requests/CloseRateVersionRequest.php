<?php

namespace Add\Hr\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A version is never rewritten: it is closed, and a new one added.
 */
class CloseRateVersionRequest extends FormRequest
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
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
