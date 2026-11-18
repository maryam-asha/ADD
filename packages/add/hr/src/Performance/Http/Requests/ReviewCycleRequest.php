<?php

namespace Add\Hr\Performance\Http\Requests;

use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating (POST) or changing (PATCH, draft cycles only) a review cycle.
 */
class ReviewCycleRequest extends FormRequest
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
        $presence = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            ...($this->isMethod('post') ? TranslatableField::rules('name') : TranslatableField::sometimes('name')),
            'review_template_id' => [$presence, 'integer', Rule::exists('hr_review_templates', 'id')->where('is_active', true)],
            'period_start' => [$presence, 'date'],
            'period_end' => [$presence, 'date', 'after_or_equal:period_start'],
            'org_unit_id' => ['nullable', 'integer', Rule::exists('hr_org_units', 'id')],
            'goals_due_on' => ['nullable', 'date'],
            'self_due_on' => ['nullable', 'date'],
            'manager_due_on' => ['nullable', 'date'],
        ];
    }
}
