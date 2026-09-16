<?php

namespace Tests\Unit\Support\Forms;

use App\Support\Forms\FieldRuleBuilder;
use App\Support\Forms\FieldType;
use App\Support\Forms\FormFieldContract;
use Tests\TestCase;

class FieldRuleBuilderTest extends TestCase
{
    private function field(
        string $key,
        FieldType $type,
        array $validation = [],
        ?array $options = null,
        bool $required = false,
        ?string $label = null,
    ): FormFieldContract {
        return new class($key, $type, $validation, $options, $required, $label) implements FormFieldContract
        {
            public function __construct(
                private string $key,
                private FieldType $type,
                private array $validation,
                private ?array $options,
                private bool $required,
                private ?string $label = null,
            ) {}

            public function fieldKey(): string
            {
                return $this->key;
            }

            public function fieldType(): FieldType
            {
                return $this->type;
            }

            public function fieldOptions(): ?array
            {
                return $this->options;
            }

            public function fieldValidation(): array
            {
                return $this->validation;
            }

            public function isFieldRequired(): bool
            {
                return $this->required;
            }

            public function fieldLabel(?string $locale = null): string
            {
                return $this->label ?? $this->key;
            }
        };
    }

    private function build(FormFieldContract ...$fields): array
    {
        return (new FieldRuleBuilder)->rulesFor($fields);
    }

    public function test_text_field_carries_string_and_its_bounds(): void
    {
        $rules = $this->build($this->field('notes', FieldType::Text, ['max' => 500, 'min' => 2]));

        $this->assertSame(['nullable', 'string', 'max:500', 'min:2'], $rules['fields.notes']);
    }

    public function test_required_comes_from_either_the_column_or_the_json(): void
    {
        $fromColumn = $this->build($this->field('a', FieldType::Text, [], null, true));
        $fromJson = $this->build($this->field('b', FieldType::Text, ['required' => true]));

        $this->assertContains('required', $fromColumn['fields.a']);
        $this->assertContains('required', $fromJson['fields.b']);
    }

    /** Regression: the constraint used to sit on the array, never on its elements. */
    public function test_checkbox_constrains_its_elements_not_the_array(): void
    {
        $rules = $this->build($this->field('topics', FieldType::Checkbox, [], [
            ['value' => 'ai'], ['value' => 'iot'],
        ]));

        $this->assertSame(['nullable', 'array'], $rules['fields.topics']);
        $this->assertSame(['string', 'in:ai,iot'], $rules['fields.topics.*']);
    }

    /** Regression: without `string`, an array slipped past `in:`. */
    public function test_select_is_constrained_to_a_string_in_its_options(): void
    {
        $rules = $this->build($this->field('size', FieldType::Select, [], [
            ['value' => 's'], ['value' => 'm'],
        ]));

        $this->assertSame(['nullable', 'string', 'in:s,m'], $rules['fields.size']);
    }

    public function test_optionless_select_emits_no_empty_in_rule(): void
    {
        $rules = $this->build($this->field('size', FieldType::Select, [], []));

        $this->assertSame(['nullable', 'string'], $rules['fields.size']);
    }

    /** Regression: a dotted key addresses a nested path, not the field. */
    public function test_a_dotted_key_is_skipped_rather_than_widening_what_is_accepted(): void
    {
        $rules = $this->build(
            $this->field('a.b', FieldType::Text),
            $this->field('ok', FieldType::Text),
        );

        $this->assertArrayNotHasKey('fields.a.b', $rules);
        $this->assertArrayHasKey('fields.ok', $rules);
    }

    public function test_rating_bounds_default_and_are_cast_to_integers(): void
    {
        $defaults = $this->build($this->field('r', FieldType::Rating));
        $given = $this->build($this->field('r', FieldType::Rating, ['min' => '2', 'max' => '7']));

        $this->assertSame(['nullable', 'integer', 'min:1', 'max:5'], $defaults['fields.r']);
        $this->assertSame(['nullable', 'integer', 'min:2', 'max:7'], $given['fields.r']);
    }

    public function test_known_accept_shorthands_become_mime_rules(): void
    {
        $rules = $this->build($this->field('cv', FieldType::File, ['accept' => 'image/*', 'max_kb' => 2048]));

        $this->assertSame(['nullable', 'file', 'mimes:jpg,jpeg,png,gif,webp,svg', 'max:2048'], $rules['fields.cv']);
    }

    /** Regression: an unmapped accept produced `mimes:application/x-foo`, matching nothing. */
    public function test_an_unmapped_accept_leaves_the_type_unconstrained_rather_than_rejecting_everything(): void
    {
        $rules = $this->build($this->field('cv', FieldType::File, ['accept' => 'application/x-foo']));

        $this->assertSame(['nullable', 'file'], $rules['fields.cv']);
    }

    public function test_a_bare_extension_list_is_passed_through(): void
    {
        $rules = $this->build($this->field('cv', FieldType::File, ['accept' => 'pdf,docx']));

        $this->assertSame(['nullable', 'file', 'mimes:pdf,docx'], $rules['fields.cv']);
    }

    public function test_number_ignores_non_numeric_bounds(): void
    {
        $rules = $this->build($this->field('n', FieldType::Number, ['min' => 'abc', 'max' => 10]));

        $this->assertSame(['nullable', 'numeric', 'max:10'], $rules['fields.n']);
    }

    public function test_the_prefix_is_configurable(): void
    {
        $rules = (new FieldRuleBuilder)->rulesFor([$this->field('x', FieldType::Date)], 'answers');

        $this->assertSame(['nullable', 'date'], $rules['answers.x']);
    }

    // ── Date bounds ──────────────────────────────────────────────────────

    public function test_a_date_field_carries_its_range(): void
    {
        $rules = $this->build($this->field('arrival', FieldType::Date, [
            'min_date' => '2026-01-01',
            'max_date' => '2026-12-31',
        ]));

        $this->assertSame(
            ['nullable', 'date', 'after_or_equal:2026-01-01', 'before_or_equal:2026-12-31'],
            $rules['fields.arrival'],
        );
    }

    public function test_a_date_bound_can_be_one_sided(): void
    {
        $from = $this->build($this->field('a', FieldType::Date, ['min_date' => '2026-06-01']));
        $until = $this->build($this->field('b', FieldType::Date, ['max_date' => '2026-06-30']));

        $this->assertSame(['nullable', 'date', 'after_or_equal:2026-06-01'], $from['fields.a']);
        $this->assertSame(['nullable', 'date', 'before_or_equal:2026-06-30'], $until['fields.b']);
    }

    /**
     * A relative bound would make the emitted rule depend on the day it was
     * built. That is a deliberate follow-up, so it must not half-work now.
     */
    public function test_a_malformed_or_relative_date_bound_is_dropped(): void
    {
        foreach (['2026-13-45', 'tomorrow', '+30 days', '2026/01/01', '', 'today'] as $bad) {
            $rules = $this->build($this->field('d', FieldType::Date, ['min_date' => $bad]));

            $this->assertSame(['nullable', 'date'], $rules['fields.d'], "`{$bad}` should not produce a bound.");
        }
    }

    public function test_an_impossible_calendar_date_is_rejected_by_checkdate(): void
    {
        // Well-formed but not a real day: 2026 is not a leap year.
        $rules = $this->build($this->field('d', FieldType::Date, ['min_date' => '2026-02-30']));

        $this->assertSame(['nullable', 'date'], $rules['fields.d']);
    }

    // ── Checkbox item counts ─────────────────────────────────────────────

    public function test_a_checkbox_can_bound_how_many_are_ticked(): void
    {
        $rules = $this->build($this->field('topics', FieldType::Checkbox, ['min' => 1, 'max' => 3], [
            ['value' => 'ai'], ['value' => 'iot'], ['value' => 'ml'],
        ]));

        $this->assertSame(['nullable', 'array', 'min:1', 'max:3'], $rules['fields.topics']);
        $this->assertSame(['string', 'in:ai,iot,ml'], $rules['fields.topics.*']);
    }

    // ── Tightened guards ─────────────────────────────────────────────────

    /** Regression: an unchecked bound reached the rule string verbatim. */
    public function test_a_non_numeric_text_bound_is_dropped_rather_than_emitted(): void
    {
        $rules = $this->build($this->field('n', FieldType::Text, ['max' => 'abc', 'min' => 2]));

        $this->assertSame(['nullable', 'string', 'min:2'], $rules['fields.n']);
    }

    /** Regression: (int) 'abc' is 0, so junk silently became min:0, not 1. */
    public function test_a_non_numeric_rating_bound_falls_back_to_the_default(): void
    {
        $rules = $this->build($this->field('r', FieldType::Rating, ['min' => 'abc']));

        $this->assertSame(['nullable', 'integer', 'min:1', 'max:5'], $rules['fields.r']);
    }

    // ── Named patterns ───────────────────────────────────────────────────

    public function test_a_named_pattern_resolves_to_its_expression(): void
    {
        $rules = $this->build($this->field('code', FieldType::Text, ['regex' => 'digits']));

        $this->assertSame(['nullable', 'string', 'regex:/^[0-9]+$/'], $rules['fields.code']);
    }

    /** email/url have better-worded native rules, already translated. */
    public function test_a_pattern_without_an_expression_emits_laravels_own_rule(): void
    {
        $rules = $this->build($this->field('site', FieldType::Text, ['regex' => 'url']));

        $this->assertSame(['nullable', 'string', 'url'], $rules['fields.site']);
    }

    /**
     * The security-relevant one. A free-form expression could inject rules
     * past Laravel's `|` split, or hang a worker. Legacy rows carrying one
     * are dropped, not honoured — and not rejected either, so a live form
     * keeps working.
     */
    public function test_a_free_form_regex_is_dropped_rather_than_honoured(): void
    {
        foreach (['/^(a+)+$/', '/^a/|exists:users,email', '.*'] as $raw) {
            $rules = $this->build($this->field('t', FieldType::Text, ['regex' => $raw]));

            $this->assertSame(['nullable', 'string'], $rules['fields.t'], "`{$raw}` must not reach the rules.");
        }
    }

    // ── Backward compatibility ───────────────────────────────────────────

    /**
     * The contract: a field with no constraints produces exactly what it
     * produced before this vocabulary existed. Rating keeps its 1..5
     * defaults; nothing else gains a bound it did not have.
     */
    public function test_an_unconstrained_field_of_every_type_is_unchanged(): void
    {
        $expected = [
            'text' => ['nullable', 'string'],
            'textarea' => ['nullable', 'string'],
            'select' => ['nullable', 'string'],
            'file' => ['nullable', 'file'],
            'checkbox' => ['nullable', 'array'],
            'date' => ['nullable', 'date'],
            'number' => ['nullable', 'numeric'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
        ];

        foreach (FieldType::cases() as $type) {
            $rules = $this->build($this->field('f', $type));

            $this->assertSame($expected[$type->value], $rules['fields.f'], "{$type->value} changed shape.");
        }
    }

    /** The four rows actually in the database today still build. */
    public function test_the_shapes_already_stored_in_production_still_build(): void
    {
        $textarea = $this->build($this->field('t', FieldType::Textarea, ['max' => 500]));
        $file = $this->build($this->field('f', FieldType::File, ['accept' => 'image/*', 'max_kb' => 5120]));
        $select = $this->build($this->field('s', FieldType::Select, ['required' => true], [['value' => 'a']]));

        $this->assertSame(['nullable', 'string', 'max:500'], $textarea['fields.t']);
        $this->assertSame(['nullable', 'file', 'mimes:jpg,jpeg,png,gif,webp,svg', 'max:5120'], $file['fields.f']);
        $this->assertSame(['required', 'string', 'in:a'], $select['fields.s']);
    }

    // ── Attribute names ──────────────────────────────────────────────────

    public function test_each_rule_path_is_named_after_its_label(): void
    {
        $attributes = (new FieldRuleBuilder)->attributesFor([
            $this->field('dietary_needs', FieldType::Text, label: 'الاحتياجات الغذائية'),
        ]);

        $this->assertSame(['fields.dietary_needs' => 'الاحتياجات الغذائية'], $attributes);
    }

    /** An element error is keyed fields.topics.0 and needs the same name. */
    public function test_a_checkbox_names_its_elements_too(): void
    {
        $attributes = (new FieldRuleBuilder)->attributesFor([
            $this->field('topics', FieldType::Checkbox, label: 'المواضيع'),
        ]);

        $this->assertSame([
            'fields.topics' => 'المواضيع',
            'fields.topics.*' => 'المواضيع',
        ], $attributes);
    }

    public function test_a_field_without_a_label_falls_back_to_its_key(): void
    {
        $attributes = (new FieldRuleBuilder)->attributesFor([$this->field('notes', FieldType::Text)]);

        $this->assertSame(['fields.notes' => 'notes'], $attributes);
    }

    public function test_attribute_names_follow_the_same_prefix_as_the_rules(): void
    {
        $attributes = (new FieldRuleBuilder)->attributesFor(
            [$this->field('x', FieldType::Text, label: 'X')],
            'answers',
        );

        $this->assertSame(['answers.x' => 'X'], $attributes);
    }
}
