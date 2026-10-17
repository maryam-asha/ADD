<?php

namespace Add\Hr\Benefits\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The month to grant, default the current one.
 */
class RunBenefitGrantsRequest extends FormRequest
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
            'month' => ['nullable', 'date_format:Y-m'],
        ];
    }
}
