<?php

namespace Add\Hr\Support\Concerns;

/**
 * Bilingual JSON columns ({"ar": ..., "en": ...}), read in the current
 * locale, then English, then Arabic.
 */
trait HasTranslations
{
    public function translate(string $field, ?string $locale = null): ?string
    {
        $value = $this->{$field};

        if (! is_array($value)) {
            return $value;
        }

        $locale ??= app()->getLocale();

        return $value[$locale] ?? $value['en'] ?? $value['ar'] ?? null;
    }
}
