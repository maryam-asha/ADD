<?php

namespace Add\Hr\Recruitment\Http\Requests;

use Add\Hr\Core\Enums\EmploymentType;
use Add\Hr\Hr;
use Add\Hr\Recruitment\Enums\RequisitionReason;
use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A request to hire, filed by the hiring manager themselves (self-service); HR names the hiring manager (StoreRequisitionRequest).
 */
class FileRequisitionRequest extends FormRequest
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
            'org_unit_id' => ['required', 'integer', Rule::exists('hr_org_units', 'id')],
            'position_id' => ['nullable', 'integer', Rule::exists('hr_positions', 'id')],
            'job_grade_id' => ['nullable', 'integer', Rule::exists('hr_job_grades', 'id')],
            ...TranslatableField::rules('title'),
            'headcount' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'employment_type' => ['required', Rule::enum(EmploymentType::class)],
            'reason' => ['required', Rule::enum(RequisitionReason::class)],
            'replaces_employee_id' => ['nullable', 'required_if:reason,'.RequisitionReason::Replacement->value, 'integer', Rule::exists('hr_employees', 'id')],
            'budget_min' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'budget_max' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'gte:budget_min'],
            'budget_currency' => ['nullable', 'required_with:budget_min,budget_max', ...Hr::currencyRules()],
            'justification' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
