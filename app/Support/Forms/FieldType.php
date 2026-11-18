<?php

namespace App\Support\Forms;

/**
 * The field types a dynamic form can render, shared by every domain that
 * builds forms — Services' service types and Experience's per-event
 * registration forms both cast their `type` column to this enum.
 *
 * It lives under App\Support rather than in either domain because
 * tests/Guards/DomainLayerBoundaryTest bans Experience and Ecosystem from
 * importing each other or from Core; that guard scans app/Domain only, so
 * a shared type has to sit outside it to be legal from all three.
 *
 * Storage quirk, deliberately preserved: Select and Rating are scalar but
 * report storesJson(), so their value lands in `value_json` as a bare
 * scalar and reads back through the same path. Every existing answer
 * (form_answers, and the per-domain tables it was backfilled from) was
 * written that way — "fixing" it to value_text would strand them.
 */
enum FieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Select = 'select';
    case File = 'file';
    case Checkbox = 'checkbox';
    case Date = 'date';
    case Number = 'number';
    case Rating = 'rating';

    /** Whether the value should be stored as JSON (see the class docblock). */
    public function storesJson(): bool
    {
        return match ($this) {
            self::Select, self::Checkbox, self::Rating => true,
            default => false,
        };
    }

    /** Whether the value should be stored as a file path. */
    public function storesFile(): bool
    {
        return $this === self::File;
    }

    /**
     * Whether a submitted value is a list rather than a scalar. Drives
     * element-level (`key.*`) validation rules.
     */
    public function isMultiValue(): bool
    {
        return $this === self::Checkbox;
    }

    /** Whether options are required for this type to mean anything. */
    public function needsOptions(): bool
    {
        return match ($this) {
            self::Select, self::Checkbox => true,
            default => false,
        };
    }
}
