<?php

namespace Add\Hr\Core\Http\Requests;

use Add\Hr\Core\Enums\ChecklistKind;
use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChecklistTemplateRequest extends FormRequest
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
            'kind' => ['required', Rule::enum(ChecklistKind::class)],
            ...TranslatableField::rules('name'),
            'items' => ['required', 'array', 'min:1', 'max:50'],
            ...TranslatableField::rules('items.*.title'),
            'items.*.due_offset_days' => ['required', 'integer', 'min:-365', 'max:365'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
