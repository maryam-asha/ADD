<?php

namespace Database\Factories;

use App\Domain\Forms\Contracts\HasForms;
use App\Domain\Forms\Enums\FormPurpose;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormField;
use App\Support\Forms\FieldType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormField>
 */
class FormFieldFactory extends Factory
{
    protected $model = FormField::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'form_id' => Form::factory(),
            'key' => 'field_'.fake()->unique()->numberBetween(1, 100000),
            'label' => ['en' => 'Question', 'ar' => 'سؤال'],
            'placeholder' => null,
            'type' => FieldType::Text,
            'options' => null,
            'validation' => [],
            'is_required' => false,
            'is_active' => true,
            'order' => 0,
        ];
    }

    /** A field on `$owner`'s form for `$purpose`, creating that form if needed. */
    public function forOwner(HasForms $owner, FormPurpose $purpose): static
    {
        return $this->state(fn () => ['form_id' => $owner->formOrCreate($purpose)->id]);
    }

    public function ofType(FieldType $type): static
    {
        return $this->state(fn () => ['type' => $type]);
    }

    public function required(): static
    {
        return $this->state(fn () => ['is_required' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * @param  list<string>  $values
     */
    public function select(array $values): static
    {
        return $this->state(fn () => [
            'type' => FieldType::Select,
            'options' => array_map(
                fn (string $v) => ['value' => $v, 'label' => ['en' => $v, 'ar' => $v]],
                $values
            ),
        ]);
    }

    /**
     * @param  list<string>  $values
     */
    public function checkbox(array $values): static
    {
        return $this->select($values)->state(fn () => ['type' => FieldType::Checkbox]);
    }
}
