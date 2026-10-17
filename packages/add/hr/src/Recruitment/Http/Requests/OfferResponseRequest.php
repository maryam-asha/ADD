<?php

namespace Add\Hr\Recruitment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The candidate's answer, as HR records it.
 */
class OfferResponseRequest extends FormRequest
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
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
