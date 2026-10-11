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
     * Human names for the generated rule paths, for Form Request
     * attributes().
     *
     * A failed dynamic rule otherwise renders `:attribute` as the dotted
     * path it was keyed by, so the member is told that "fields.dietary_needs"
     * is too long rather than being told which question is. The label is
     * already stored bilingually and already shipped to clients; it was
     * simply never reaching the validator.
     *
     * @param  iterable<FormFieldContract>  $fields
     * @return array<string, string>
     */
    public function attributesFor(iterable $fields, string $prefix = 'fields'): array
    {
        $attributes = [];

        foreach ($fields as $field) {
            $key = $field->fieldKey();

            if (str_contains($key, '.')) {
                continue; // Skipped by rulesFor() too; no rule to name.
            }

            $label = $field->fieldLabel();
            $path = "{$prefix}.{$key}";

            $attributes[$path] = $label;

            // A checkbox element error is keyed fields.topics.0, which would
            // otherwise read as a bare index.
            if ($field->fieldType() === FieldType::Checkbox) {
                $attributes["{$path}.*"] = $label;
            }
        }

        return $attributes;
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
                // Character counts. Guarded and cast: an unchecked value used
                // to reach the rule string verbatim.
                foreach (['max', 'min'] as $bound) {
                    if (isset($v[$bound]) && is_numeric($v[$bound])) {
                        $rules[] = $bound.':'.(int) $v[$bound];
                    }
                }
                if (isset($v['regex']) && $pattern = $this->patternRule((string) $v['regex'])) {
                    $rules[] = $pattern;
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
                // Absolute calendar bounds. Deliberately NOT parsed with
                // Carbon: these are dates, not instants, and comparing them
                // needs no timezone. That also keeps this clear of
                // tests/Guards/UserSuppliedTimesAreInterpretedLocallyTest,
                // which bans Carbon::parse on user-supplied times.
                if ($from = $this->dateBound($v, FieldConstraints::MIN_DATE)) {
                    $rules[] = "after_or_equal:{$from}";
                }
                if ($until = $this->dateBound($v, FieldConstraints::MAX_DATE)) {
                    $rules[] = "before_or_equal:{$until}";
                }
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
                // How many boxes may be ticked: Laravel counts elements when
                // min/max are applied to an array, so the item-count meaning
                // falls out of the same keys.
                foreach (['min', 'max'] as $bound) {
                    if (isset($v[$bound]) && is_numeric($v[$bound])) {
                        $rules[] = $bound.':'.(int) $v[$bound];
                    }
                }
                if ($in = $this->inRule($field)) {
                    $extra["{$path}.*"] = ['string', $in];
                }
                break;

            case FieldType::Rating:
                $rules[] = 'integer';
                $rules[] = 'min:'.$this->ratingBound($v, 'min', 1);
                $rules[] = 'max:'.$this->ratingBound($v, 'max', 5);
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
     * A named pattern resolved to a rule, or null.
     *
     * `regex` used to be a free-form expression concatenated straight into
     * the rule string. Laravel splits rules on `|`, so a stored
     * `/^a/|exists:users,email` appended two rules to a member-facing
     * validator; and a nested quantifier like `(a+)+$` hangs the worker on a
     * long input. Only the names in FieldConstraints::PATTERNS are honoured
     * now.
     *
     * An unrecognised value is dropped and logged rather than rejected, the
     * same posture acceptToMimes() already takes: a live configuration must
     * not start refusing every submission because of a legacy row.
     */
    private function patternRule(string $name): ?string
    {
        if (! FieldConstraints::isPatternName($name)) {
            Log::warning('Unknown form field `regex` pattern; text left unconstrained.', [
                'pattern' => Str::limit($name, 100),
            ]);

            return null;
        }

        $pattern = FieldConstraints::PATTERNS[$name];

        // A null entry means Laravel has a better-worded rule of its own,
        // already translated in lang/{en,ar}/validation.php.
        return $pattern === null ? $name : "regex:{$pattern}";
    }

    /**
     * A `YYYY-MM-DD` bound, or null when absent or malformed.
     *
     * checkdate() rather than strtotime(): the latter accepts "tomorrow" and
     * "+1 week", which would make the emitted rule depend on when it was
     * built. Relative bounds are a deliberate follow-up, not an accident.
     */
    private function dateBound(array $validation, string $key): ?string
    {
        $value = $validation[$key] ?? null;

        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }

    /** A rating bound, falling back to the documented default when unusable. */
    private function ratingBound(array $validation, string $key, int $default): int
    {
        $value = $validation[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
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
