<?php

namespace Add\Hr\Time\Http\Requests;

use Add\Hr\Time\Enums\ExceptionResolution;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveExceptionRequest extends FormRequest
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
            'resolution' => ['required', Rule::enum(ExceptionResolution::class)],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
