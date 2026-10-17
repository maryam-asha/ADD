<?php

namespace Add\Hr\Time\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReprocessAttendanceRequest extends FormRequest
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
            'to' => ['required', 'date', 'after_or_equal:from', 'before_or_equal:'.date('Y-m-d', strtotime('+61 days', strtotime((string) $this->input('from')) ?: time()))],
            'employee_id' => ['nullable', 'integer', Rule::exists('hr_employees', 'id')],
        ];
    }
}
