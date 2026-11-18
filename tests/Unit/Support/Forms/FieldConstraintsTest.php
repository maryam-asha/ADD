<?php

namespace Tests\Unit\Support\Forms;

use App\Support\Forms\FieldConstraints;
use App\Support\Forms\FieldType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FieldConstraintsTest extends TestCase
{
    public static function everyType(): array
    {
        return array_map(fn (FieldType $t) => [$t], FieldType::cases());
    }

    #[DataProvider('everyType')]
    public function test_every_field_type_declares_its_vocabulary(FieldType $type): void
    {
        // match() without a default would fatal on a new case; this catches
        // the same omission as a failure rather than a 500 in production.
        $this->assertIsArray(FieldConstraints::for($type));
    }

    #[DataProvider('everyType')]
    public function test_every_type_accepts_required(FieldType $type): void
    {
        // Rows already carry it and the rule builder ORs it with the column.
        $this->assertTrue(FieldConstraints::allows($type, 'required'));
    }

    #[DataProvider('everyType')]
    public function test_allows_agrees_with_the_declared_vocabulary(FieldType $type): void
    {
        foreach (FieldConstraints::for($type) as $key => $shape) {
            $this->assertTrue(FieldConstraints::allows($type, $key));
            $this->assertSame($shape, FieldConstraints::shapeOf($type, $key));
        }

        $this->assertFalse(FieldConstraints::allows($type, 'definitely_not_a_key'));
        $this->assertNull(FieldConstraints::shapeOf($type, 'definitely_not_a_key'));
    }

    /**
     * The vocabulary is duplicated in the admin UI, which has no way to read
     * this class:
     *
     *   ADDOS/src/add-os/components/resource/form-fields.ts
     *     -> VALIDATION_KEYS_BY_TYPE
     *
     * There is no shared-schema mechanism between the two repos, so this
     * snapshot is the thing that fails when they drift. If you changed the
     * vocabulary deliberately, update that file in the same change.
     */
    public function test_the_vocabulary_matches_what_the_admin_ui_ships(): void
    {
        $expected = [
            'text' => ['required', 'min', 'max', 'regex'],
            'textarea' => ['required', 'min', 'max', 'regex'],
            'select' => ['required'],
            'file' => ['required', 'accept', 'max_kb'],
            'checkbox' => ['required', 'min', 'max'],
            'date' => ['required', 'min_date', 'max_date'],
            'number' => ['required', 'min', 'max'],
            'rating' => ['required', 'min', 'max'],
        ];

        $actual = [];
        foreach (FieldType::cases() as $type) {
            $actual[$type->value] = array_keys(FieldConstraints::for($type));
        }

        $this->assertSame(
            $expected,
            $actual,
            'The validation vocabulary changed. Update VALIDATION_KEYS_BY_TYPE in '
            .'ADDOS/src/add-os/components/resource/form-fields.ts to match.'
        );
    }

    /** A date bound must never collide with the numeric min/max. */
    public function test_dates_use_their_own_keys_rather_than_min_and_max(): void
    {
        $date = FieldConstraints::for(FieldType::Date);

        $this->assertArrayHasKey('min_date', $date);
        $this->assertArrayHasKey('max_date', $date);
        $this->assertArrayNotHasKey('min', $date);
        $this->assertArrayNotHasKey('max', $date);
    }

    public function test_ordered_pairs_name_the_keys_that_type_actually_has(): void
    {
        foreach (FieldType::cases() as $type) {
            foreach (FieldConstraints::orderedPairs($type) as [$lower, $upper]) {
                $this->assertTrue(
                    FieldConstraints::allows($type, $lower) && FieldConstraints::allows($type, $upper),
                    "{$type->value} orders {$lower}/{$upper} but does not accept them."
                );
            }
        }
    }

    public function test_select_constrains_nothing_beyond_its_options(): void
    {
        $this->assertSame(['required'], array_keys(FieldConstraints::for(FieldType::Select)));
    }

    /**
     * Every pattern must compile, be anchored, and carry no nested quantifier.
     * A catastrophic pattern here would hang a worker on a member submission,
     * which is the whole reason free-form regex was dropped.
     */
    public function test_every_named_pattern_is_anchored_and_compiles(): void
    {
        foreach (FieldConstraints::PATTERNS as $name => $pattern) {
            if ($pattern === null) {
                continue; // Emits Laravel's own rule instead.
            }

            $this->assertSame(
                0,
                preg_match($pattern, 'a probe string') === false ? 1 : 0,
                "Pattern `{$name}` does not compile."
            );

            $this->assertMatchesRegularExpression(
                '/^\/\^/',
                $pattern,
                "Pattern `{$name}` is not start-anchored."
            );

            $this->assertDoesNotMatchRegularExpression(
                '/[+*]\)[+*]/',
                $pattern,
                "Pattern `{$name}` nests a quantifier, which is the ReDoS shape."
            );
        }
    }

    public function test_pattern_names_are_recognised_and_others_are_not(): void
    {
        $this->assertTrue(FieldConstraints::isPatternName('digits'));
        $this->assertTrue(FieldConstraints::isPatternName('email'));

        // The shape an admin used to be able to store.
        $this->assertFalse(FieldConstraints::isPatternName('/^(a+)+$/'));
        $this->assertFalse(FieldConstraints::isPatternName(''));
    }
}
