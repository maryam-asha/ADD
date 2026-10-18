<?php

namespace Add\Hr\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The full list of dependents; it replaces the stored one.
 */
class ReplaceDependentsRequest extends FormRequest
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
            'dependents' => ['present', 'array', 'max:20'],
            'dependents.*.name' => ['required', 'string', 'max:255'],
            'dependents.*.relation' => ['required', 'string', 'max:50'],
            'dependents.*.date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }
}
