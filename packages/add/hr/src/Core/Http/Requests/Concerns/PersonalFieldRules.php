<?php

namespace Add\Hr\Core\Http\Requests\Concerns;

use Add\Hr\Core\Enums\Gender;
use Add\Hr\Core\Enums\MaritalStatus;
use Illuminate\Validation\Rule;

/**
 * The employee's personal data, shared by hiring and editing.
 */
trait PersonalFieldRules
{
    /**
     * @return array<string, mixed>
     */
    protected function personalFieldRules(?int $ignoreEmployeeId = null): array
    {
        return [
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'nationality' => ['nullable', 'string', 'size:2'],
            'marital_status' => ['nullable', Rule::enum(MaritalStatus::class)],
            'phone' => ['nullable', 'string', 'max:32'],
            'personal_email' => ['nullable', 'email', 'max:255'],
            'work_email' => ['nullable', 'email', 'max:255', Rule::unique('hr_employees', 'work_email')->ignore($ignoreEmployeeId)],
            'address' => ['nullable', 'string', 'max:500'],
            'national_id' => ['nullable', 'string', 'max:64'],
            'bank_account' => ['nullable', 'string', 'max:64'],
        ];
    }
}
