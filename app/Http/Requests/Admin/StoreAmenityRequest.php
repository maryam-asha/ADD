<?php

namespace App\Http\Requests\Admin;

use App\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;

class StoreAmenityRequest extends FormRequest
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
            [
                // Derived from the name when left out.
                'key' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', 'unique:amenities,key'],
                'icon' => ['nullable', 'string', 'max:40'],
                'order' => ['nullable', 'integer', 'min:0'],
                'is_active' => ['nullable', 'boolean'],
            ]
        );
    }
}
