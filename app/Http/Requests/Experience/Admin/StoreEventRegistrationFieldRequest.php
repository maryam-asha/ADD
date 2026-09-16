<?php

namespace App\Http\Requests\Experience\Admin;

use App\Support\Forms\FieldType;
use App\Support\Forms\ReservedFieldKeys;
use App\Support\Forms\Rules\ValidationVocabularyRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEventRegistrationFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the admin route group already guards this
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $eventId = $this->route('event')?->id ?? $this->route('event');

        return [
            // Lower snake_case only: a dot would address a nested path
            // rather than the field when rules are built.
            'key' => [
                'required', 'string', 'max:80',
                'regex:/^[a-z][a-z0-9_]*$/',
                ReservedFieldKeys::rule(),
                Rule::unique('event_registration_fields', 'key')->where('event_id', $eventId),
            ],
            'label' => ['required', 'array'],
            'label.en' => ['required', 'string', 'max:255'],
            'label.ar' => ['required', 'string', 'max:255'],
            'placeholder' => ['nullable', 'array'],
            'placeholder.en' => ['nullable', 'string', 'max:255'],
            'placeholder.ar' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::enum(FieldType::class)],
            // Select and checkbox are meaningless without choices, and an
            // empty option set would accept any value at submit time.
            'options' => [
                Rule::requiredIf(fn () => in_array($this->input('type'), ['select', 'checkbox'], true)),
                'nullable', 'array', 'min:1',
            ],
            'options.*.value' => ['required', 'string', 'max:100'],
            'options.*.label' => ['required', 'array'],
            // Which keys are legal depends on the field's type, which a
            // declarative rule cannot express — see ValidationVocabularyRule.
            'validation' => ['nullable', 'array', new ValidationVocabularyRule($this->constrainedType())],
            'is_required' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
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

        return null;
    }
}
