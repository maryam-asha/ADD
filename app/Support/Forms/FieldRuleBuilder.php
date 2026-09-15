<?php

namespace App\Support\Forms;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turns stored field definitions into Laravel validation rules, so adding
 * or changing a form field is a DB edit rather than a code change.
 *
 * One field can produce more than one rule key: a checkbox needs both an
 * `array` rule on the key itself and an `in:` rule on its elements, which
 * is why this returns a keyed array rather than a rule list per field.
 */
class FieldRuleBuilder
{
    /**
     * @param  iterable<FormFieldContract>  $fields
     * @return array<string, array<int, string>>
     */
    public function rulesFor(iterable $fields, string $prefix = 'fields'): array
    {
        $rules = [];

        foreach ($fields as $field) {
            $key = $field->fieldKey();

            // A dot in a key would address a nested path rather than the
            // field itself. The write layer rejects these, but rows that
            // predate that rule must not silently widen what is accepted.
            if (Str::contains($key, '.')) {
                continue;
            }

            foreach ($this->rulesForField($field, "{$prefix}.{$key}") as $path => $pathRules) {
                $rules[$path] = $pathRules;
            }
        }

        return $rules;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rulesForField(FormFieldContract $field, string $path): array
    {
        $v = $field->fieldValidation();
        $type = $field->fieldType();

        $rules = [
            ($v['required'] ?? false) || $field->isFieldRequired() ? 'required' : 'nullable',
        ];

        $extra = [];

        switch ($type) {
            case FieldType::Text:
            case FieldType::Textarea:
                $rules[] = 'string';
                foreach (['max', 'min'] as $bound) {
                    if (isset($v[$bound])) {
                        $rules[] = "{$bound}:{$v[$bound]}";
                    }
                }
                if (isset($v['regex'])) {
                    $rules[] = "regex:{$v['regex']}";
                }
                break;

            case FieldType::Number:
                $rules[] = 'numeric';
                foreach (['min', 'max'] as $bound) {
                    if (isset($v[$bound]) && is_numeric($v[$bound])) {
                        $rules[] = "{$bound}:{$v[$bound]}";
                    }
                }
                break;

            case FieldType::Date:
                $rules[] = 'date';
                break;

            case FieldType::Select:
                // `string` was missing here: without it an array or object
                // slips past `in:` unpredictably.
                $rules[] = 'string';
                if ($in = $this->inRule($field)) {
                    $rules[] = $in;
                }
                break;

            case FieldType::Checkbox:
                // `in:` used to be applied to the array itself, which never
                // validates the elements. The constraint belongs on `.*`.
                $rules[] = 'array';
                if ($in = $this->inRule($field)) {
                    $extra["{$path}.*"] = ['string', $in];
                }
                break;

            case FieldType::Rating:
                $rules[] = 'integer';
                $rules[] = 'min:'.(int) ($v['min'] ?? 1);
                $rules[] = 'max:'.(int) ($v['max'] ?? 5);
                break;

            case FieldType::File:
                $rules[] = 'file';
                if (isset($v['accept']) && $mimes = $this->acceptToMimes((string) $v['accept'])) {
                    $rules[] = $mimes;
                }
                if (isset($v['max_kb']) && is_numeric($v['max_kb'])) {
                    $rules[] = 'max:'.(int) $v['max_kb'];
                }
                break;
        }

        return [$path => $rules] + $extra;
    }

    /**
     * `in:` over the field's option values, or null when it has none —
     * emitting a bare `in:` would reject every value.
     */
    private function inRule(FormFieldContract $field): ?string
    {
        $values = collect($field->fieldOptions() ?? [])
            ->pluck('value')
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->implode(',');

        return $values === '' ? null : "in:{$values}";
    }

    /**
     * Map an `accept` shorthand to a `mimes:` rule. An unmapped value used
     * to be passed through verbatim, producing rules like
     * `mimes:application/pdf` that match nothing and reject every upload —
     * so an unknown value now drops the constraint and is logged instead.
     */
    private function acceptToMimes(string $accept): ?string
    {
        return match (trim(strtolower($accept))) {
            'image/*' => 'mimes:jpg,jpeg,png,gif,webp,svg',
            'video/*' => 'mimes:mp4,mov,avi,mkv',
            'audio/*' => 'mimes:mp3,wav,ogg,m4a',
            '.pdf', 'application/pdf' => 'mimes:pdf',
            '.doc', '.docx' => 'mimes:doc,docx',
            '.xls', '.xlsx' => 'mimes:xls,xlsx',
            default => $this->unmappedAccept($accept),
        };
    }

    private function unmappedAccept(string $accept): ?string
    {
        // A bare extension list ("jpg,png") is already what `mimes:` wants.
        if (preg_match('/^[a-z0-9]+(,[a-z0-9]+)*$/i', trim($accept))) {
            return 'mimes:'.trim($accept);
        }

        Log::warning('Unmapped form field `accept` value; file type left unconstrained.', [
            'accept' => $accept,
        ]);

        return null;
    }
}
