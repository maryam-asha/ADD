<?php

namespace Add\Hr\Time\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAttendancePolicyRequest extends FormRequest
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
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'early_leave_grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'min_overtime_minutes' => ['sometimes', 'integer', 'min:0', 'max:480'],
            'overtime_requires_pre_approval' => ['sometimes', 'boolean'],
            'entry_tolerance_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
        ];
    }
}
