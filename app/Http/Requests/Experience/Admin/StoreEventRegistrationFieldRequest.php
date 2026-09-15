<?php

namespace App\Http\Requests\Experience\Admin;

use App\Support\Forms\FieldType;
use App\Support\Forms\ReservedFieldKeys;
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
