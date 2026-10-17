<?php

namespace Add\Hr\Time\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Each entry sets a day (a shift, or rest with a null shift), or removes
 * the day's entry with `remove: true` so the work pattern applies again.
 */
class SaveRosterRequest extends FormRequest
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
            'entries' => ['required', 'array', 'min:1', 'max:500'],
            'entries.*.employee_id' => ['required', 'integer', Rule::exists('hr_employees', 'id')],
            'entries.*.work_date' => ['required', 'date'],
            'entries.*.shift_template_id' => ['nullable', 'integer', Rule::exists('hr_shift_templates', 'id')],
            'entries.*.remove' => ['sometimes', 'boolean'],
            'entries.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
