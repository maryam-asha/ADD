<?php

namespace Add\Hr\Core\Http\Requests;

use Add\Hr\Core\Enums\ContractType;
use Add\Hr\Core\Enums\EmploymentType;
use Add\Hr\Core\Enums\WorkMode;
use Add\Hr\Core\Http\Requests\Concerns\PersonalFieldRules;
use Add\Hr\Hr;
use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Everything a hire records at once: the person, their first contract and
 * their first assignment.
 */
class HireEmployeeRequest extends FormRequest
{
    use PersonalFieldRules;

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
            ...TranslatableField::rules('name'),
            ...$this->personalFieldRules(),
            'employee_number' => ['nullable', 'string', 'max:32', 'unique:hr_employees,employee_number'],
            'user_id' => ['nullable', Rule::exists(Hr::usersTable(), 'id'), Rule::unique('hr_employees', 'user_id')],
            'hired_on' => ['required', 'date'],

            'contract' => ['required', 'array'],
            'contract.type' => ['required', Rule::enum(ContractType::class)],
            'contract.ends_on' => ['nullable', Rule::requiredIf($this->input('contract.type') === ContractType::FixedTerm->value), 'date', 'after:hired_on'],
            'contract.probation_ends_on' => ['nullable', 'date', 'after_or_equal:hired_on'],
            'contract.notice_period_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'contract.reference' => ['nullable', 'string', 'max:100'],

            'assignment' => ['required', 'array'],
            'assignment.org_unit_id' => ['required', 'integer', 'exists:hr_org_units,id'],
            'assignment.position_id' => ['nullable', 'integer', 'exists:hr_positions,id'],
            'assignment.job_grade_id' => ['nullable', 'integer', 'exists:hr_job_grades,id'],
            'assignment.manager_id' => ['nullable', 'integer', 'exists:hr_employees,id'],
            'assignment.work_location_id' => ['nullable', 'integer', 'exists:hr_work_locations,id'],
            ...TranslatableField::rules('assignment.job_title', required: false),
            'assignment.employment_type' => ['required', Rule::enum(EmploymentType::class)],
            'assignment.work_mode' => ['required', Rule::enum(WorkMode::class)],
        ];
    }
}
