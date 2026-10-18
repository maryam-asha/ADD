<?php

namespace Add\Hr\Leave\Http\Requests;

use Add\Hr\Leave\Enums\HalfDay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A leave request, or a quote for one. Multipart when an attachment is sent.
 */
class SubmitLeaveRequest extends FormRequest
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
            'leave_type_id' => ['required', 'integer', Rule::exists('hr_leave_types', 'id')],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'half_day' => ['nullable', Rule::enum(HalfDay::class)],
            'hours' => ['nullable', 'numeric', 'gt:0', 'max:24'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'attachment' => [
                'nullable',
                'file',
                'mimes:'.implode(',', (array) config('hr.documents.mimes')),
                'max:'.(int) config('hr.documents.max_kb'),
            ],
        ];
    }
}
