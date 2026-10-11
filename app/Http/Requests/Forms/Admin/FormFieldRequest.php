<?php

namespace App\Http\Requests\Forms\Admin;

use App\Http\Routing\FormRouteContext;
use App\Support\Forms\FieldType;
use App\Support\Forms\ReservedFieldKeys;
use App\Support\Forms\Rules\ValidationVocabularyRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * One definition of a valid form field, for every owner's editor. Replaces
 * four per-domain requests that had drifted apart — different label
 * lengths, options required on one side only, key uniqueness checked on
 * create but not on rename.
 */
abstract class FormFieldRequest extends FormRequest
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
        $choice = $this->resolvedType()?->needsOptions() ?? false;

        return [
            // Lower snake_case only: a dot would address a nested path
            // rather than the field when rules are built. Identity comes
            // from the member's account, never from a field.
            'key' => [
                ...$this->presence(), 'string', 'max:80',
                'regex:/^[a-z][a-z0-9_]*$/',
                ReservedFieldKeys::rule(),
                $this->uniqueKey(),
            ],
            'label' => [...$this->presence(), 'array'],
            'label.en' => ['required_with:label', 'string', 'max:255'],
            'label.ar' => ['required_with:label', 'string', 'max:255'],
            'placeholder' => ['nullable', 'array'],
            'placeholder.en' => ['nullable', 'string', 'max:255'],
            'placeholder.ar' => ['nullable', 'string', 'max:255'],
            'type' => [...$this->presence(), Rule::enum(FieldType::class)],
            // Select and checkbox are meaningless without choices, and an
            // empty option set would accept any value at submit time.
            'options' => array_values(array_filter([
                Rule::requiredIf(fn () => $choice && $this->optionsRequired()),
                'nullable', 'array',
                $choice ? 'min:1' : null,
            ])),
            'options.*.value' => ['required', 'string', 'max:100'],
            'options.*.label' => ['required', 'array'],
            'options.*.label.*' => ['nullable', 'string', 'max:255'],
            // Which keys are legal depends on the field's type, which a
            // declarative rule cannot express — see ValidationVocabularyRule.
            'validation' => ['nullable', 'array', new ValidationVocabularyRule($this->resolvedType())],
            // `sometimes`, not `nullable`: the columns are NOT NULL, so an
            // explicit null is refused here rather than failing the insert.
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

    protected function context(): FormRouteContext
    {
        return FormRouteContext::fromRequest($this);
    }

    /**
     * The type whose vocabulary applies: the one being set, or else the one
     * stored — a PATCH that only changes a label carries no `type`.
     */
    protected function resolvedType(): ?FieldType
    {
        $given = $this->input('type');

        return is_string($given) ? FieldType::tryFrom($given) : $this->storedType();
    }

    /** @return list<string> `required` on create, `sometimes|required` on update. */
    abstract protected function presence(): array;

    abstract protected function uniqueKey(): Unique;

    /** Whether a choice field must come with `options` in this request. */
    abstract protected function optionsRequired(): bool;

    abstract protected function storedType(): ?FieldType;
}
