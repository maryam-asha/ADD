<?php

namespace Add\Hr\Time\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The employee's own times for a day, local `H:i`.
 */
class SubmitAttendanceEntryRequest extends FormRequest
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
            'work_date' => ['required', 'date'],
            'check_in' => ['nullable', 'required_without:check_out', 'date_format:H:i'],
            'check_out' => ['nullable', 'required_without:check_in', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
