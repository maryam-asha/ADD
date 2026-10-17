<?php

namespace Add\Hr\Support;

/**
 * The write-side shape of a bilingual field: Arabic required, English
 * optional — the counterpart to HasTranslations.
 */
class TranslatableField
{
    /**
     * @return array<string, list<string>>
     */
    public static function rules(string $field, bool $required = true): array
    {
        $presence = $required ? 'required' : 'nullable';

        return [
            $field => [$presence, 'array'],
            "$field.ar" => [$presence, 'string', 'max:255'],
            "$field.en" => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * For updates: the field may be left out, but when sent it still needs
     * its Arabic value.
     *
     * @return array<string, list<string>>
     */
    public static function sometimes(string $field): array
    {
        return [
            $field => ['sometimes', 'array'],
            "$field.ar" => ["required_with:$field", 'string', 'max:255'],
            "$field.en" => ['nullable', 'string', 'max:255'],
        ];
    }
}
