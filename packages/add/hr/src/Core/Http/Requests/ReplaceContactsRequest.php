<?php

namespace Add\Hr\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The full list of emergency contacts; it replaces the stored one.
 */
class ReplaceContactsRequest extends FormRequest
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
            'contacts' => ['present', 'array', 'max:5'],
            'contacts.*.name' => ['required', 'string', 'max:255'],
            'contacts.*.relation' => ['required', 'string', 'max:50'],
            'contacts.*.phone' => ['required', 'string', 'max:32'],
        ];
    }
}
