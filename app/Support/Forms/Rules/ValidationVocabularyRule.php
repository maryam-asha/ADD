<?php

namespace App\Support\Forms\Rules;

use App\Support\Forms\FieldConstraints;
use App\Support\Forms\FieldType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses a `validation` payload the rule builder would not honour.
 *
 * Before this existed the admin API stored whatever it was given: an unknown
 * key was kept and ignored, `max_kb` on a text field was accepted and then
 * silently dropped at submit time, and a free-form `regex` went straight into
 * a rule string. The admin got no feedback; the member got a form that did
 * not constrain what the admin thought it did.
 *
 * This is a rule object rather than a set of declarative `validation.*` lines
 * because the vocabulary is per-type — a declarative rule cannot say "this
 * key is illegal for *this* type" — and because the four admin Form Requests
 * (Store/Update x Events/Services) would otherwise each carry their own copy
 * to drift apart.
 *
 * Errors are keyed to the offending sub-key (`validation.min_date`), so the
 * admin UI can show them against the control that caused them.
 */
class ValidationVocabularyRule implements ValidationRule
{
    public function __construct(private ?FieldType $type) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === []) {
            return;
        }

        if (! is_array($value)) {
            $fail(__('api.validation.vocabulary_not_an_object'));

            return;
        }

        // `type` is absent on a PATCH that only touches a label, and the
        // caller resolves it from the model in that case. If it could not be
        // resolved at all the type rule itself is already failing, so say
        // nothing here rather than emit a second, more confusing error.
        if ($this->type === null) {
            return;
        }

        foreach ($value as $key => $entry) {
            $this->validateKey($attribute, (string) $key, $entry, $fail);
        }

        $this->validateOrdering($attribute, $value, $fail);
    }

    private function validateKey(string $attribute, string $key, mixed $entry, Closure $fail): void
    {
        $shape = FieldConstraints::shapeOf($this->type, $key);

        if ($shape === null) {
            $fail(__('api.validation.constraint_not_allowed_for_type', [
                'key' => $key,
                'type' => $this->type->value,
            ]));

            return;
        }

        match ($shape) {
            FieldConstraints::BOOLEAN => $this->requireBoolean($attribute, $key, $entry, $fail),
            FieldConstraints::INTEGER => $this->requireInteger($attribute, $key, $entry, $fail),
            FieldConstraints::NUMERIC => $this->requireNumeric($attribute, $key, $entry, $fail),
            FieldConstraints::DATE => $this->requireDate($attribute, $key, $entry, $fail),
            FieldConstraints::PATTERN => $this->requirePattern($attribute, $key, $entry, $fail),
            FieldConstraints::ACCEPT => $this->requireAccept($attribute, $key, $entry, $fail),
            default => null,
        };
    }

    private function requireBoolean(string $attribute, string $key, mixed $entry, Closure $fail): void
    {
        if (! is_bool($entry)) {
            $fail(__('api.validation.constraint_must_be_boolean', ['key' => $key]));
        }
    }

    private function requireInteger(string $attribute, string $key, mixed $entry, Closure $fail): void
    {
        if (! is_numeric($entry) || (int) $entry != $entry) {
            $fail(__('api.validation.constraint_must_be_whole', ['key' => $key]));

            return;
        }

        // A size of zero kilobytes accepts nothing; a negative count is
        // meaningless everywhere.
        $floor = $key === 'max_kb' ? 1 : 0;

        if ((int) $entry < $floor) {
            $fail(__('api.validation.constraint_below_minimum', ['key' => $key, 'min' => $floor]));
        }
    }

    private function requireNumeric(string $attribute, string $key, mixed $entry, Closure $fail): void
    {
        if (! is_numeric($entry)) {
            $fail(__('api.validation.constraint_must_be_numeric', ['key' => $key]));
        }
    }

    private function requireDate(string $attribute, string $key, mixed $entry, Closure $fail): void
    {
        // A calendar date, never an instant: no timezone is involved in
        // "no later than 2026-12-31", and parsing it as one would be the bug.
        // Relative bounds ("today", "+30 days") are a deliberate follow-up —
        // rejecting them now keeps them from half-working.
        if (! is_string($entry) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $entry, $m)) {
            $fail(__('api.validation.constraint_must_be_a_date', ['key' => $key]));

            return;
        }

        if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $fail(__('api.validation.constraint_not_a_real_date', ['key' => $key, 'date' => $entry]));
        }
    }

    private function requirePattern(string $attribute, string $key, mixed $entry, Closure $fail): void
    {
        if (! is_string($entry) || ! FieldConstraints::isPatternName($entry)) {
            $fail(__('api.validation.constraint_unknown_pattern', [
                'key' => $key,
                'patterns' => implode(', ', FieldConstraints::patternNames()),
            ]));
        }
    }

    private function requireAccept(string $attribute, string $key, mixed $entry, Closure $fail): void
    {
        if (! is_string($entry) || trim($entry) === '') {
            $fail(__('api.validation.constraint_must_be_text', ['key' => $key]));
        }
    }

    /**
     * A lower bound above its upper one accepts nothing at all, which is
     * never what the admin meant.
     */
    private function validateOrdering(string $attribute, array $value, Closure $fail): void
    {
        foreach (FieldConstraints::orderedPairs($this->type) as [$lower, $upper]) {
            if (! isset($value[$lower], $value[$upper])) {
                continue;
            }

            $from = $value[$lower];
            $until = $value[$upper];

            $ordered = FieldConstraints::shapeOf($this->type, $lower) === FieldConstraints::DATE
                ? (is_string($from) && is_string($until) && $from <= $until)
                : (is_numeric($from) && is_numeric($until) && $from <= $until);

            // Only complain about ordering when both values are otherwise
            // sound; a malformed one already has its own error.
            if (! $ordered && $this->bothWellFormed($from, $until)) {
                $fail(__('api.validation.constraint_range_inverted', [
                    'min' => $lower,
                    'max' => $upper,
                ]));
            }
        }
    }

    private function bothWellFormed(mixed $from, mixed $until): bool
    {
        if (FieldConstraints::shapeOf($this->type, FieldConstraints::MIN_DATE) === FieldConstraints::DATE
            && is_string($from) && is_string($until)) {
            return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)
                && (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $until);
        }

        return is_numeric($from) && is_numeric($until);
    }
}
