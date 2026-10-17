<?php

namespace Add\Hr\Core\Http\Requests;

use Add\Hr\Core\Enums\ContractType;
use Add\Hr\Core\Enums\EmploymentEventType;
use Add\Hr\Core\Enums\EmploymentType;
use Add\Hr\Core\Enums\TerminationReason;
use Add\Hr\Core\Enums\WorkMode;
use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One employment event. `type` decides which other fields apply; a hire is
 * not an event here (POST employees). Business rules (dates against the
 * current contract, seats, the reporting line) are the service's.
 */
class RecordEmploymentEventRequest extends FormRequest
{
    private const ASSIGNMENT_CHANGES = ['promotion', 'transfer', 'assignment_change'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $type = $this->input('type');
        $rehire = $type === EmploymentEventType::Rehire->value;
        $changesAssignment = in_array($type, self::ASSIGNMENT_CHANGES, true);
        $needsContract = $rehire || $type === EmploymentEventType::ContractRenewal->value;

        return [
            'type' => ['required', Rule::enum(EmploymentEventType::class)->except([EmploymentEventType::Hire])],
            'effective_on' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_on'],
            'reason' => $type === EmploymentEventType::Termination->value
                ? ['required', Rule::enum(TerminationReason::class)]
                : ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'contract' => [$needsContract ? 'required' : 'prohibited', 'array'],
            'contract.type' => [Rule::requiredIf($needsContract), Rule::enum(ContractType::class)],
            'contract.ends_on' => ['nullable', Rule::requiredIf($this->input('contract.type') === ContractType::FixedTerm->value), 'date', 'after:effective_on'],
            'contract.probation_ends_on' => [$rehire ? 'nullable' : 'prohibited', 'date', 'after_or_equal:effective_on'],
            'contract.notice_period_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'contract.reference' => ['nullable', 'string', 'max:100'],

            'assignment' => [$rehire || $changesAssignment ? 'required' : 'prohibited', 'array'],
            'assignment.org_unit_id' => [Rule::requiredIf($rehire), 'integer', 'exists:hr_org_units,id'],
            'assignment.position_id' => ['nullable', 'integer', 'exists:hr_positions,id'],
            'assignment.job_grade_id' => ['nullable', 'integer', 'exists:hr_job_grades,id'],
            'assignment.manager_id' => ['nullable', 'integer', 'exists:hr_employees,id'],
            'assignment.work_location_id' => ['nullable', 'integer', 'exists:hr_work_locations,id'],
            ...TranslatableField::rules('assignment.job_title', required: false),
            'assignment.employment_type' => [Rule::requiredIf($rehire), Rule::enum(EmploymentType::class)],
            'assignment.work_mode' => [Rule::requiredIf($rehire), Rule::enum(WorkMode::class)],
        ];
    }
}
