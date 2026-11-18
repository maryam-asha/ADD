<?php

namespace Add\Hr\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateChecklistTaskRequest extends FormRequest
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
            'completed' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
