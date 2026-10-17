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
}
