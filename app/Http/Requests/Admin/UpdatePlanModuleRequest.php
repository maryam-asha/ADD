<?php

namespace App\Http\Requests\Admin;

use App\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlanModuleRequest extends FormRequest
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
        $moduleId = $this->route('planModule')?->id ?? $this->route('plan_module');

        return array_merge(
            TranslatableField::rules('name'),
            TranslatableField::rules('description', false),
            [
                'slug' => ['nullable', 'string', 'max:255', Rule::unique('plan_modules', 'slug')->ignore($moduleId)],
                'icon' => ['nullable', 'string', 'max:100'],
                'order' => ['nullable', 'integer', 'min:0'],
                'is_active' => ['nullable', 'boolean'],
            ]
        );
    }
}
