<?php

namespace Add\Hr\Time\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Copies the seven days from `from` onto the seven days from `to`.
 */
class CopyRosterWeekRequest extends FormRequest
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
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'different:from'],
            'employee_ids' => ['required_without:org_unit_id', 'array', 'max:500'],
            'employee_ids.*' => ['integer', Rule::exists('hr_employees', 'id')],
            'org_unit_id' => ['required_without:employee_ids', 'integer', Rule::exists('hr_org_units', 'id')],
        ];
    }
}
