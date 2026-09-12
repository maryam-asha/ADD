<?php

namespace App\Http\Requests\Admin;

use App\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEventCategoryRequest extends FormRequest
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
        $category = $this->route('eventCategory') ?? $this->route('event_category');
        $categoryId = $category instanceof \App\Domain\Experience\Models\EventCategory ? $category->id : $category;

        return array_merge(
            TranslatableField::rules('name'),
            TranslatableField::rules('description', required: false),
            [
                'slug' => [
                    'nullable',
                    'string',
                    'max:100',
                    Rule::unique('event_categories', 'slug')->ignore($categoryId),
                ],
                'color' => ['nullable', 'string', 'max:50'],
                'icon' => ['nullable', 'string', 'max:100'],
                'order' => ['nullable', 'integer', 'min:0'],
                'is_active' => ['nullable', 'boolean'],
            ]
        );
    }
}
