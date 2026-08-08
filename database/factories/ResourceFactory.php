<?php

namespace Database\Factories;

use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\ResourceCategory;
use App\Domain\Foundation\Models\Resource;
use App\Domain\Foundation\Models\Space;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Domain\Foundation\Models\Resource>
 */
class ResourceFactory extends Factory
{
    protected $model = Resource::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'space_id' => Space::factory(),
            'name' => fake()->randomElement(['Projector', 'Wireless Mic', 'Display Screen', 'Whiteboard']),
            'category' => fake()->randomElement(ResourceCategory::cases()),
            'quantity' => fake()->numberBetween(1, 4),
            'status' => OperationalStatus::Active,
        ];
    }

    public function maintenance(): static
    {
        return $this->state([
            'status' => OperationalStatus::Maintenance,
            'status_reason' => fake()->sentence(),
            'status_from' => now(),
        ]);
    }
}
