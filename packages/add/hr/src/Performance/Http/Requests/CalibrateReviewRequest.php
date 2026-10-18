<?php

namespace Add\Hr\Performance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CalibrateReviewRequest extends FormRequest
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
            'score' => ['required', 'numeric'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
