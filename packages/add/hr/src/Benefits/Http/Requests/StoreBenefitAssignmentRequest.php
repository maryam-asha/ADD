<?php

namespace Add\Hr\Benefits\Http\Requests;

use Add\Hr\Benefits\Enums\BenefitKind;
use Add\Hr\Benefits\Models\Benefit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Exactly one target: employee_id, job_grade_id, or org_unit_id (with its
 * sub-units). `excluded` keeps one employee out of what their grade or
 * unit receives.
 */
class StoreBenefitAssignmentRequest extends FormRequest
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
        /** @var Benefit $benefit */
        $benefit = $this->route('benefit');

        return [
            'employee_id' => ['required_without_all:job_grade_id,org_unit_id', 'prohibits:job_grade_id,org_unit_id', 'nullable', 'integer', Rule::exists('hr_employees', 'id')],
            'job_grade_id' => ['required_without_all:employee_id,org_unit_id', 'prohibits:employee_id,org_unit_id', 'nullable', 'integer', Rule::exists('hr_job_grades', 'id')],
            'org_unit_id' => ['required_without_all:employee_id,job_grade_id', 'prohibits:employee_id,job_grade_id', 'nullable', 'integer', Rule::exists('hr_org_units', 'id')],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'employer_cost' => [$benefit->kind === BenefitKind::CashAllowance ? 'prohibited' : 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'excluded' => ['sometimes', Rule::prohibitedIf(fn () => $this->input('employee_id') === null), 'boolean'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }
}
