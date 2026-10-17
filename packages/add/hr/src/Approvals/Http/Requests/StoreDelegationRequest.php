<?php

namespace Add\Hr\Approvals\Http\Requests;

use Add\Hr\Approvals\Services\ApprovalTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDelegationRequest extends FormRequest
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
            'delegate_employee_id' => ['required', 'integer', Rule::exists('hr_employees', 'id')],
            'starts_on' => ['required', 'date', 'after_or_equal:today'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'request_type' => ['nullable', 'string', Rule::in(app(ApprovalTypes::class)->types())],
        ];
    }
}
