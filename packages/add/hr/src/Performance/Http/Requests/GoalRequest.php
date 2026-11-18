<?php

namespace Add\Hr\Performance\Http\Requests;

use Add\Hr\Performance\Enums\GoalStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Setting (POST) or updating (PATCH) a goal. In self-service a manager names
 * the report it is for with employee_id; GoalService decides what may still
 * change.
 */
class GoalRequest extends FormRequest
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
        $creating = $this->isMethod('post');

        return [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'metric' => ['nullable', 'string', 'max:255'],
            'target' => ['nullable', 'string', 'max:255'],
            'weight' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'progress' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'due_on' => ['nullable', 'date'],
            ...($creating ? [
                'review_cycle_id' => ['nullable', 'integer', Rule::exists('hr_review_cycles', 'id')],
                'employee_id' => ['sometimes', 'integer', Rule::exists('hr_employees', 'id')],
            ] : [
                'status' => ['sometimes', Rule::enum(GoalStatus::class)],
            ]),
        ];
    }
}
