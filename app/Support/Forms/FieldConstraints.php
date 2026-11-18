<?php

namespace App\Support\Forms;

/**
 * What an admin may constrain, per field type.
 *
 * This is the single specification of the `validation` JSON vocabulary. Both
 * halves of the engine read it: FieldRuleBuilder turns the stored keys into
 * Laravel rules, and ValidationVocabularyRule refuses to store a key this
 * class does not allow for the chosen type. One match(), so they cannot
 * drift — before this existed the only spec was a docblock on
 * FormFieldContract, and the write layer enforced three keys out of six.
 *
 * It lives under App\Support for the same reason FieldType does:
 * tests/Guards/DomainLayerBoundaryTest scans app/Domain only, so shared code
 * has to sit outside it to be legal from Experience, Ecosystem and Services
 * alike.
 *
 * Backward compatibility is not optional here. `min`, `max`, `required`,
 * `accept` and `max_kb` are already persisted in production rows and already
 * shipped to a member app we do not control, so they keep their exact
 * meanings. Dates are the one case that gets new keys rather than reusing
 * `min`/`max` — see MIN_DATE below.
 *
 * See docs/decisions/event-approval-and-custom-forms.md.
 */
final class FieldConstraints
{
    /** A whole number of characters, items or kilobytes. */
    public const INTEGER = 'integer';

    /** Any number, fractional included — a value bound rather than a count. */
    public const NUMERIC = 'numeric';

    public const BOOLEAN = 'boolean';

    /** An absolute calendar date, `YYYY-MM-DD`. Never an instant. */
    public const DATE = 'date';

    /** One of PATTERNS' keys. Never a raw expression — see PATTERNS. */
    public const PATTERN = 'pattern';

    /** An `accept` shorthand FieldRuleBuilder knows how to map to mimes. */
    public const ACCEPT = 'accept';

    /**
     * Date bounds deliberately do NOT reuse `min`/`max`.
     *
     * Those two are numbers for every type that already uses them —
     * characters, values, stars, item counts. A date bound is a string, and
     * the obvious naive consumption in a client we cannot inspect
     * (`input.maxLength = field.validation.max`) would silently set a
     * maxLength of "2026-12-31". A new key is ignored by an old client; a
     * redefined one corrupts it.
     */
    public const MIN_DATE = 'min_date';

    public const MAX_DATE = 'max_date';

    /**
     * The patterns an admin may pick, by name.
     *
     * A free-form regex used to be accepted and concatenated straight into
     * `"regex:{$value}"`. That was two holes at once. Laravel splits a rule
     * string on `|`, so a stored `/^a/|exists:users,email` appended two extra
     * rules to a validator that faces members — an existence oracle handed to
     * anyone who can edit a field. And a pattern like `(a+)+$` against a long
     * input hangs the worker outright; no attacker is needed, that shape is
     * ordinary copy-paste.
     *
     * Every pattern here is anchored and linear-time: no nested quantifier,
     * no backreference, no alternation over overlapping branches.
     *
     * A null value means "emit Laravel's own rule instead of a pattern" —
     * `email` and `url` are already translated in lang/{en,ar}/validation.php,
     * so the member gets a real message rather than "format is invalid".
     */
    public const PATTERNS = [
        'alpha' => '/^[\p{L}\s]+$/u',
        'alphanumeric' => '/^[\p{L}\p{N}\s]+$/u',
        'digits' => '/^[0-9]+$/',
        'email' => null,
        'url' => null,
        'syrian_phone' => '/^(\+?963|0)9[0-9]{8}$/',
        'no_urls' => '/^(?!.*https?:\/\/).*$/is',
    ];

    /**
     * The keys this type accepts, mapped to the shape each must hold.
     *
     * `required` is allowed on every type because rows already carry it and
     * FieldRuleBuilder ORs it with the `is_required` column. The admin UI
     * does not write it — one fact, one control.
     *
     * @return array<string, string>
     */
    public static function for(FieldType $type): array
    {
        $common = ['required' => self::BOOLEAN];

        return $common + match ($type) {
            // Character counts.
            FieldType::Text, FieldType::Textarea => [
                'min' => self::INTEGER,
                'max' => self::INTEGER,
                'regex' => self::PATTERN,
            ],

            // A value bound, so fractions are legitimate.
            FieldType::Number => [
                'min' => self::NUMERIC,
                'max' => self::NUMERIC,
            ],

            FieldType::Date => [
                self::MIN_DATE => self::DATE,
                self::MAX_DATE => self::DATE,
            ],

            // How many boxes may be ticked. Laravel's min/max count elements
            // on an array, so the same keys and the same rule strings give
            // item-count semantics for free.
            FieldType::Checkbox => [
                'min' => self::INTEGER,
                'max' => self::INTEGER,
            ],

            // Stars. Defaults to 1..5 when unset — see FieldRuleBuilder.
            FieldType::Rating => [
                'min' => self::INTEGER,
                'max' => self::INTEGER,
            ],

            FieldType::File => [
                'accept' => self::ACCEPT,
                'max_kb' => self::INTEGER,
            ],

            // The option list is the constraint; nothing to add.
            FieldType::Select => [],
        };
    }

    /**
     * Pairs that must satisfy lower <= upper, for the types that have one.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function orderedPairs(FieldType $type): array
    {
        return match ($type) {
            FieldType::Date => [[self::MIN_DATE, self::MAX_DATE]],

            FieldType::Text, FieldType::Textarea,
            FieldType::Number, FieldType::Checkbox,
            FieldType::Rating => [['min', 'max']],

            // Select has no bounds at all, and File's only pair would be
            // max_kb against nothing — listing min/max here would order a
            // pair neither type accepts.
            FieldType::Select, FieldType::File => [],
        };
    }

    public static function allows(FieldType $type, string $key): bool
    {
        return array_key_exists($key, self::for($type));
    }

    /** The shape a key must hold for this type, or null if it is not allowed. */
    public static function shapeOf(FieldType $type, string $key): ?string
    {
        return self::for($type)[$key] ?? null;
    }

    /** @return list<string> */
    public static function patternNames(): array
    {
        return array_keys(self::PATTERNS);
    }

    public static function isPatternName(string $name): bool
    {
        return array_key_exists($name, self::PATTERNS);
    }
}
