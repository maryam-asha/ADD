<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\DropsNullDefaults;
use App\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;

class StoreEventCategoryRequest extends FormRequest
{
    use DropsNullDefaults;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->dropNullDefaults(['order', 'is_active']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(
            TranslatableField::rules('name'),
            TranslatableField::rules('description', required: false),
            [
                'slug' => ['nullable', 'string', 'max:100', 'unique:event_categories,slug'],
                'color' => ['nullable', 'string', 'max:50'],
                'icon' => ['nullable', 'string', 'max:100'],
                'order' => ['nullable', 'integer', 'min:0'],
                'is_active' => ['nullable', 'boolean'],
            ]
        );
    }
}
