<?php

namespace Add\Hr\Approvals\Http\Requests;

use Add\Hr\Approvals\Enums\ApproverType;
use Add\Hr\Approvals\Enums\TimeoutAction;
use Add\Hr\Approvals\Services\ApprovalTypes;
use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApprovalFlowRequest extends FormRequest
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
            'request_type' => ['required', 'string', Rule::in(app(ApprovalTypes::class)->types()), Rule::unique('hr_approval_flows', 'request_type')],
            ...TranslatableField::rules('name', required: false),
            ...self::stepRules('required'),
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function stepRules(string $presence): array
    {
        return [
            'steps' => [$presence, 'array', 'min:1', 'max:10'],
            'steps.*.approver' => ['required', Rule::enum(ApproverType::class)],
            'steps.*.ability' => ['required_if:steps.*.approver,'.ApproverType::Ability->value, 'nullable', 'string', 'max:64'],
            'steps.*.employee_id' => ['required_if:steps.*.approver,'.ApproverType::Employee->value, 'nullable', 'integer', Rule::exists('hr_employees', 'id')],
            'steps.*.timeout_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'steps.*.on_timeout' => ['nullable', Rule::enum(TimeoutAction::class)],
        ];
    }

    /**
     * Each step with only the keys its approver type uses. A timeout without
     * an action escalates.
     *
     * @return list<array<string, mixed>>|null
     */
    public function steps(): ?array
    {
        if (! $this->has('steps')) {
            return null;
        }

        return array_map(fn (array $step) => array_filter([
            'approver' => $step['approver'],
            'ability' => $step['approver'] === ApproverType::Ability->value ? $step['ability'] : null,
            'employee_id' => $step['approver'] === ApproverType::Employee->value ? (int) $step['employee_id'] : null,
            'timeout_hours' => isset($step['timeout_hours']) ? (int) $step['timeout_hours'] : null,
            'on_timeout' => isset($step['timeout_hours']) ? ($step['on_timeout'] ?? TimeoutAction::Escalate->value) : null,
        ], fn ($value) => $value !== null), array_values($this->validated('steps')));
    }
}
