<?php

namespace App\Http\Requests\Services\Admin;

use App\Support\Forms\FieldType;
use App\Support\Forms\ReservedFieldKeys;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceTypeFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $types = collect(FieldType::cases())->map->value->all();
        $typeId = $this->route('serviceType')?->id;

        return [
            // Identity comes from the member's account, and a dot in a key
            // would address a nested path rather than the field itself.
            'key' => ['required', 'string', 'max:80',
                'regex:/^[a-z][a-z0-9_]*$/',
                ReservedFieldKeys::rule(),
                Rule::unique('service_type_fields', 'key')->where('service_type_id', $typeId)],
            'label' => ['required', 'array'],
            'label.en' => ['required', 'string', 'max:150'],
            'label.ar' => ['required', 'string', 'max:150'],
            'placeholder' => ['nullable', 'array'],
            'type' => ['required', 'string', Rule::in($types)],
            'options' => ['nullable', 'array'],
            'options.*.value' => ['required_with:options', 'string'],
            'options.*.label' => ['required_with:options', 'array'],
            'options.*.label.en' => ['required_with:options', 'string'],
            'options.*.label.ar' => ['required_with:options', 'string'],
            'validation' => ['nullable', 'array'],
            'is_required' => ['boolean'],
            'is_active' => ['boolean'],
            'order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'key.not_in' => __('api.validation.reserved_field_key', ['key' => $this->input('key')]),
        ];
    }
}
