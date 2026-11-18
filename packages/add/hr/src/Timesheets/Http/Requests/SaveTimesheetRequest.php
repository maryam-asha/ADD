<?php

namespace Add\Hr\Timesheets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The week's lines, replacing whatever it had.
 */
class SaveTimesheetRequest extends FormRequest
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
            'entries' => ['present', 'array', 'max:200'],
            'entries.*.cost_center_id' => ['required', 'integer', Rule::exists('hr_cost_centers', 'id')],
            'entries.*.work_date' => ['required', 'date'],
            'entries.*.hours' => ['required', 'numeric', 'gt:0', 'max:24'],
            'entries.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
