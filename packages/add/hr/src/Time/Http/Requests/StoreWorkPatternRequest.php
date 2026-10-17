<?php

namespace Add\Hr\Time\Http\Requests;

use Add\Hr\Support\TranslatableField;
use Add\Hr\Time\Enums\WorkPatternKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * fixed: `weekly` maps ISO weekdays (1 = Monday) to a shift template, or
 * null for rest; a weekday left out is rest. flexible: `weekly_hours`.
 */
class StoreWorkPatternRequest extends FormRequest
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
        $kind = $this->input('kind');

        return [
            'code' => ['required', 'string', 'alpha_dash', 'max:32', Rule::unique('hr_work_patterns', 'code')],
            ...TranslatableField::rules('name'),
            'kind' => ['required', Rule::enum(WorkPatternKind::class)],
            'weekly' => ['nullable', Rule::requiredIf($kind === WorkPatternKind::Fixed->value), 'array'],
            'weekly.*' => ['nullable', 'integer', Rule::exists('hr_shift_templates', 'id')],
            'weekly_hours' => ['nullable', Rule::requiredIf($kind === WorkPatternKind::Flexible->value), 'numeric', 'min:1', 'max:84'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attributesToSave(): array
    {
        $data = $this->validated();

        if (array_key_exists('weekly', $data) && $data['weekly'] !== null) {
            $data['weekly'] = collect(range(1, 7))
                ->mapWithKeys(fn (int $day) => [(string) $day => isset($data['weekly'][$day]) ? (int) $data['weekly'][$day] : null])
                ->all();
        }

        return $data;
    }
}
