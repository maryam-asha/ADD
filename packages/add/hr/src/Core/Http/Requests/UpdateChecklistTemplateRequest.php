<?php

namespace Add\Hr\Core\Http\Requests;

use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The kind is fixed once created; items are replaced as a whole list.
 */
class UpdateChecklistTemplateRequest extends FormRequest
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
            ...TranslatableField::sometimes('name'),
            'items' => ['sometimes', 'array', 'min:1', 'max:50'],
            ...TranslatableField::rules('items.*.title'),
            'items.*.due_offset_days' => ['required_with:items', 'integer', 'min:-365', 'max:365'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
