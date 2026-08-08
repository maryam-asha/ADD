<?php

namespace Database\Factories;

use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Floor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Floor>
 */
class FloorFactory extends Factory
{
    protected $model = Floor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'building_id' => Building::factory(),
            'label' => (string) fake()->unique()->numberBetween(1, 20),
            'sort_order' => 0,
        ];
    }
}
