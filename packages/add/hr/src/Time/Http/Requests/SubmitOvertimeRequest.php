<?php

namespace Add\Hr\Time\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitOvertimeRequest extends FormRequest
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
            'minutes' => ['required', 'integer', 'min:15', 'max:720'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
