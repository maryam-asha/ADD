<?php

namespace App\Http\Requests\Admin;

use App\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateResourceTypeRequest extends FormRequest
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
        $resourceTypeId = $this->route('resourceType')?->id ?? $this->route('resource_type');

        return array_merge(
            TranslatableField::rules('name'),
            TranslatableField::rules('unit_label'),
            [
                'slug' => ['nullable', 'string', 'max:255', Rule::unique('resource_types', 'slug')->ignore($resourceTypeId)],
                'icon' => ['nullable', 'string', 'max:100'],
                'order' => ['nullable', 'integer', 'min:0'],
                'is_active' => ['nullable', 'boolean'],
            ]
        );
    }
}
