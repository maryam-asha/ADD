<?php

namespace App\Http\Requests\Forms\Admin;

use App\Support\Forms\FieldType;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;

class StoreFormFieldRequest extends FormFieldRequest
{
    /**
     * An owner may refuse new fields outright — an event that sends
     * registration elsewhere has no questions of its own. Keyed to `key`
     * so the builder shows it against the field being added rather than as
     * a bare banner.
     */
    protected function prepareForValidation(): void
    {
        $context = $this->context();
        $reason = $context->owner->formFieldCreationBlockedReason($context->purpose);

        if ($reason !== null) {
            throw ValidationException::withMessages(['key' => [$reason]]);
        }
    }

    protected function presence(): array
    {
        return ['required'];
    }

    protected function uniqueKey(): Unique
    {
        // No form yet means no sibling to collide with; 0 matches no row.
        return Rule::unique('form_fields', 'key')->where('form_id', $this->context()->form()?->id ?? 0);
    }

    protected function optionsRequired(): bool
    {
        return true;
    }

    protected function storedType(): ?FieldType
    {
        return null;
    }
}
