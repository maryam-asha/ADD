<?php

namespace Add\Hr\Time\Http\Requests;

use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An end at or before the start runs past midnight.
 */
class StoreShiftTemplateRequest extends FormRequest
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
            'code' => ['required', 'string', 'alpha_dash', 'max:32', Rule::unique('hr_shift_templates', 'code')],
            ...TranslatableField::rules('name'),
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'different:starts_at'],
            'break_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
