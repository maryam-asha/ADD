<?php

namespace App\Http\Requests\Admin;

use App\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreResourceTypeRequest extends FormRequest
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
        return array_merge(
            TranslatableField::rules('name'),
            TranslatableField::rules('unit_label'),
            [
                'slug' => ['nullable', 'string', 'max:255', Rule::unique('resource_types', 'slug')],
                'icon' => ['nullable', 'string', 'max:100'],
                'order' => ['nullable', 'integer', 'min:0'],
                'is_active' => ['nullable', 'boolean'],
            ]
        );
    }
}
