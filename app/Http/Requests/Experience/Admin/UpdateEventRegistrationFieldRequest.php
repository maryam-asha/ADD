<?php

namespace App\Http\Requests\Experience\Admin;

use App\Support\Forms\FieldType;
use App\Support\Forms\ReservedFieldKeys;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEventRegistrationFieldRequest extends FormRequest
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
        $event = $this->route('event');
        $eventId = is_object($event) ? $event->id : $event;

        $field = $this->route('field');
        $fieldId = is_object($field) ? $field->id : $field;

        $type = $this->input('type', is_object($field) ? $field->type?->value : null);

        return [
            'key' => [
                'sometimes', 'required', 'string', 'max:80',
                'regex:/^[a-z][a-z0-9_]*$/',
                ReservedFieldKeys::rule(),
                Rule::unique('event_registration_fields', 'key')
                    ->where('event_id', $eventId)
                    ->ignore($fieldId),
            ],
            'label' => ['sometimes', 'required', 'array'],
            'label.en' => ['sometimes', 'required', 'string', 'max:255'],
            'label.ar' => ['sometimes', 'required', 'string', 'max:255'],
            'placeholder' => ['nullable', 'array'],
            'placeholder.en' => ['nullable', 'string', 'max:255'],
            'placeholder.ar' => ['nullable', 'string', 'max:255'],
            'type' => ['sometimes', 'required', Rule::enum(FieldType::class)],
            'options' => [
                Rule::requiredIf(fn () => in_array($type, ['select', 'checkbox'], true)),
                'nullable', 'array', 'min:1',
            ],
            'options.*.value' => ['required', 'string', 'max:100'],
            'options.*.label' => ['required', 'array'],
            'validation' => ['nullable', 'array'],
            'validation.min' => ['nullable', 'numeric'],
            'validation.max' => ['nullable', 'numeric'],
            'validation.max_kb' => ['nullable', 'integer', 'min:1'],
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
}
