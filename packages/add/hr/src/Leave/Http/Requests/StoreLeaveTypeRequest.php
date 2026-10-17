<?php

namespace Add\Hr\Leave\Http\Requests;

use Add\Hr\Leave\Enums\LeaveUnit;
use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeaveTypeRequest extends FormRequest
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
            'code' => ['required', 'string', 'alpha_dash', 'max:32', Rule::unique('hr_leave_types', 'code')],
            ...TranslatableField::rules('name'),
            'unit' => ['sometimes', Rule::enum(LeaveUnit::class)],
            ...self::flagRules(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function flagRules(): array
    {
        return [
            'is_paid' => ['sometimes', 'boolean'],
            'tracks_balance' => ['sometimes', 'boolean'],
            'allows_half_day' => ['sometimes', 'boolean'],
            'counts_calendar_days' => ['sometimes', 'boolean'],
            'hide_reason' => ['sometimes', 'boolean'],
            'attachment_document_type_id' => ['nullable', 'integer', Rule::exists('hr_document_types', 'id')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
