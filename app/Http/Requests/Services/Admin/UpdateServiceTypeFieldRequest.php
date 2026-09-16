<?php

namespace App\Http\Requests\Services\Admin;

use App\Support\Forms\FieldType;
use App\Support\Forms\ReservedFieldKeys;
use App\Support\Forms\Rules\ValidationVocabularyRule;
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
            'key' => ['sometimes', 'string', 'max:80',
                'regex:/^[a-z][a-z0-9_]*$/',
                ReservedFieldKeys::rule()],
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
            // Which keys are legal depends on the field's type, which a
            // declarative rule cannot express — see ValidationVocabularyRule.
            'validation' => ['nullable', 'array', new ValidationVocabularyRule($this->constrainedType())],
            'is_required' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'order' => ['sometimes', 'integer', 'min:0'],
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

    /**
     * The type whose vocabulary applies, or null when it cannot be resolved.
     *
     * `type` is absent from a PATCH that only changes a label, so fall back
     * to the stored field — the same input-or-model resolution this request
     * already uses for `options`.
     */
    private function constrainedType(): ?FieldType
    {
        $given = $this->input('type');

        if (is_string($given)) {
            return FieldType::tryFrom($given);
        }

        $field = $this->route('field');

        if (is_object($field) && $field->type instanceof FieldType) {
            return $field->type;
        }

        return null;
    }
}
