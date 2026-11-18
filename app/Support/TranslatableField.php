<?php

namespace App\Support;

/**
 * Every bilingual JSON field ({ar, en}) validates the same shape — this is
 * the counterpart to App\Concerns\HasTranslations on the read side, so that
 * shape doesn't get copy-pasted into every Store/Update request.
 */
class TranslatableField
{
    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(string $field, bool $required = true): array
    {
        $presence = $required ? 'required' : 'nullable';

        return [
            $field => [$presence, 'array'],
            "$field.ar" => [$presence, 'string'],
            "$field.en" => ['nullable', 'string'],
        ];
    }
}
