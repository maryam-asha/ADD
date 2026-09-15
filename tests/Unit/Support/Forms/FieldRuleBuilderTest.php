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
    ): FormFieldContract {
        return new class($key, $type, $validation, $options, $required) implements FormFieldContract
        {
            public function __construct(
                private string $key,
                private FieldType $type,
                private array $validation,
                private ?array $options,
                private bool $required,
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
}
