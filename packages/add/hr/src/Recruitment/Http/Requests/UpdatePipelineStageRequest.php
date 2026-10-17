<?php

namespace Add\Hr\Recruitment\Http\Requests;

use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Only open stages change; a deactivated one takes no new moves.
 */
class UpdatePipelineStageRequest extends FormRequest
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
            ...TranslatableField::sometimes('name'),
            'sort' => ['sometimes', 'integer', 'min:1', 'max:79'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
