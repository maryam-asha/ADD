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
     * Compact validation fragments as stored — {required, min, max, regex,
     * accept, max_kb} — not Laravel rule strings.
     *
     * @return array<string, mixed>
     */
    public function fieldValidation(): array;

    public function isFieldRequired(): bool;
}
