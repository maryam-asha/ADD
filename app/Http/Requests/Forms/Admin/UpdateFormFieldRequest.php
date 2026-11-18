<?php

namespace App\Http\Requests\Forms\Admin;

use App\Domain\Forms\Models\FormField;
use App\Support\Forms\FieldType;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class UpdateFormFieldRequest extends FormFieldRequest
{
    private ?FormField $field = null;

    /** The field being edited, found within this owner's form only (404 otherwise). */
    public function field(): FormField
    {
        return $this->field ??= $this->context()->field($this);
    }

    protected function presence(): array
    {
        return ['sometimes', 'required'];
    }

    protected function uniqueKey(): Unique
    {
        return Rule::unique('form_fields', 'key')
            ->where('form_id', $this->field()->form_id)
            ->ignore($this->field()->id);
    }

    /**
     * Options are demanded only when the request touches them: when it sends
     * a set (which then may not be empty), or when it turns a field into a
     * choice field that has none stored. A PATCH that only renames a
     * select must not be refused for leaving its options alone.
     */
    protected function optionsRequired(): bool
    {
        return $this->has('options')
            || ($this->has('type') && empty($this->field()->options));
    }

    protected function storedType(): ?FieldType
    {
        return $this->field()->type;
    }
}
