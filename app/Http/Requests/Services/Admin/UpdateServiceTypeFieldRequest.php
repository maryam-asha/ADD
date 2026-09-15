<?php

namespace App\Http\Requests\Services\Admin;

use App\Support\Forms\FieldType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceTypeFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $types = collect(FieldType::cases())->map->value->all();

        return [
            'key' => ['sometimes', 'string', 'max:80'],
            'label' => ['sometimes', 'array'],
            'label.en' => ['required_with:label', 'string', 'max:150'],
            'label.ar' => ['required_with:label', 'string', 'max:150'],
            'placeholder' => ['nullable', 'array'],
            'type' => ['sometimes', 'string', Rule::in($types)],
            'options' => ['nullable', 'array'],
            'options.*.value' => ['required_with:options', 'string'],
            'options.*.label' => ['required_with:options', 'array'],
            'options.*.label.en' => ['required_with:options', 'string'],
            'options.*.label.ar' => ['required_with:options', 'string'],
            'validation' => ['nullable', 'array'],
            'is_required' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
