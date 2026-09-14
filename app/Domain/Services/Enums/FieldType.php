<?php

namespace App\Domain\Services\Enums;

enum FieldType: string
{
    case Text     = 'text';
    case Textarea = 'textarea';
    case Select   = 'select';
    case File     = 'file';
    case Checkbox = 'checkbox';
    case Date     = 'date';
    case Number   = 'number';
    case Rating   = 'rating';

    /** Whether the value should be stored as JSON (multi-value) */
    public function storesJson(): bool
    {
        return match ($this) {
            self::Select, self::Checkbox, self::Rating => true,
            default => false,
        };
    }

    /** Whether the value should be stored as a file path */
    public function storesFile(): bool
    {
        return $this === self::File;
    }
}
