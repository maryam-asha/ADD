<?php

namespace Add\Hr\Time\Http\Requests;

use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Changing the times changes past days only when they are rebuilt; locked
 * months never are.
 */
class UpdateShiftTemplateRequest extends FormRequest
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
            'code' => ['sometimes', 'string', 'alpha_dash', 'max:32', Rule::unique('hr_shift_templates', 'code')->ignore($this->route('shiftTemplate'))],
            ...TranslatableField::sometimes('name'),
            'starts_at' => ['required_with:ends_at', 'date_format:H:i'],
            'ends_at' => ['required_with:starts_at', 'date_format:H:i', 'different:starts_at'],
            'break_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
