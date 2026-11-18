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
            ...$this->limitRules(),
        ];
    }

    /**
     * The shortest and longest single request, in the type's unit; empty for
     * no limit. The longest is never below the shortest.
     *
     * @return array<string, mixed>
     */
    protected function limitRules(): array
    {
        return [
            'min_request' => ['nullable', 'numeric', 'min:0', 'max:366'],
            'max_request' => ['nullable', 'numeric', 'gt:0', 'max:366', ...($this->filled('min_request') ? ['gte:min_request'] : [])],
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
