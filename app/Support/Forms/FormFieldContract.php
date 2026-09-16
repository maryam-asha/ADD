<?php

namespace App\Support\Forms;

/**
 * What FieldRuleBuilder and FieldValueRouter need from a field definition,
 * regardless of which domain owns the row. ServiceTypeField and
 * EventRegistrationField both implement it, which is what lets one rule
 * builder serve both without either domain importing the other.
 */
interface FormFieldContract
{
    public function fieldKey(): string;

    public function fieldType(): FieldType;

    /**
     * Choices for select/checkbox: [{value: string, label: {en, ar}}].
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function fieldOptions(): ?array;

    /**
     * Compact validation fragments as stored, not Laravel rule strings.
     *
     * FieldConstraints is the specification of which keys are legal for
     * which type — this docblock used to list them and went stale the
     * moment the vocabulary grew.
     *
     * @return array<string, mixed>
     */
    public function fieldValidation(): array;

    public function isFieldRequired(): bool;

    /**
     * The field's label in the current locale, for validation messages.
     *
     * Without it a failed rule renders `:attribute` as the dotted path, so
     * an Arabic member reads "fields.dietary_needs" rather than the label
     * the admin wrote.
     */
    public function fieldLabel(?string $locale = null): string;
}
