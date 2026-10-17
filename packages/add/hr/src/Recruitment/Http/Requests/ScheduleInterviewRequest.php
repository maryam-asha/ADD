<?php

namespace Add\Hr\Recruitment\Http\Requests;

use Add\Hr\Recruitment\Enums\InterviewMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `scheduled_at` is local wall-clock time.
 */
class ScheduleInterviewRequest extends FormRequest
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
            'scheduled_at' => ['required', 'date_format:Y-m-d H:i'],
            'duration_minutes' => ['sometimes', 'integer', 'min:15', 'max:480'],
            'mode' => ['required', Rule::enum(InterviewMode::class)],
            'location' => ['nullable', 'string', 'max:255'],
            'interviewer_ids' => ['required', 'array', 'min:1', 'max:10'],
            'interviewer_ids.*' => ['integer', 'distinct', Rule::exists('hr_employees', 'id')->whereNull('terminated_on')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
