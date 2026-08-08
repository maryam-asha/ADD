<?php

namespace Database\Factories;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Building>
 */
class BuildingFactory extends Factory
{
    protected $model = Building::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => [
                'en' => 'Building '.fake()->unique()->numberBetween(1, 99),
                'ar' => 'المبنى '.fake()->unique()->numberBetween(100, 199),
            ],
            'floor_count' => fake()->numberBetween(1, 6),
        ];
    }
}
