<?php

namespace Add\Hr\Performance\Http\Requests;

use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating (POST) or changing (PATCH) a review template. Changes reach only
 * cycles launched afterwards: a launched cycle keeps its own copy.
 */
class ReviewTemplateRequest extends FormRequest
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
            'scale_max' => [$presence, 'integer', 'min:3', 'max:10'],
            'competencies' => [$presence, 'array', 'min:1', 'max:30'],
            'competencies.*.code' => ['required', 'string', 'alpha_dash', 'max:32', 'distinct'],
            'competencies.*.name' => ['required', 'array'],
            'competencies.*.name.ar' => ['required', 'string', 'max:255'],
            'competencies.*.name.en' => ['nullable', 'string', 'max:255'],
            'competencies.*.weight' => ['nullable', 'integer', 'min:1', 'max:100'],
            'goals_weight' => [$presence, 'integer', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
