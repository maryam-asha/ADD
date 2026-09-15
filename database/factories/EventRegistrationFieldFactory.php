<?php

namespace Database\Factories;

use App\Domain\Experience\Models\Event;
use App\Domain\Experience\Models\EventRegistrationField;
use App\Support\Forms\FieldType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventRegistrationField>
 */
class EventRegistrationFieldFactory extends Factory
{
    protected $model = EventRegistrationField::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
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
