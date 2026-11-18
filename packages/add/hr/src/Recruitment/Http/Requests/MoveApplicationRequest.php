<?php

namespace Add\Hr\Recruitment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * To an open stage, by code.
 */
class MoveApplicationRequest extends FormRequest
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
            'stage' => ['required', 'string', 'max:32'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
