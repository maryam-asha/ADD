<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\DropsNullDefaults;
use App\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePartnerRequest extends FormRequest
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
                'logo_url' => ['nullable', 'string', 'max:2048'],
                'website_url' => ['nullable', 'url'],
                'category' => ['required', Rule::in(['local', 'global'])],
                'order' => ['nullable', 'integer', 'min:0'],
                'is_active' => ['nullable', 'boolean'],
            ]
        );
    }
}
