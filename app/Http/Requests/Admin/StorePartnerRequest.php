<?php

namespace App\Http\Requests\Admin;

use App\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePartnerRequest extends FormRequest
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
            TranslatableField::rules('description', required: false),
            [
                'logo_url' => ['nullable', 'url'],
                'website_url' => ['nullable', 'url'],
                'category' => ['required', Rule::in(['local', 'global'])],
                'order' => ['nullable', 'integer', 'min:0'],
                'is_active' => ['nullable', 'boolean'],
            ]
        );
    }
}
