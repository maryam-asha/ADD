<?php

namespace Add\Inventory\Database\Factories;

use Add\Inventory\Catalog\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    protected $model = Unit::class;

    public function definition(): array
    {
        return [
            'code' => 'unit_'.fake()->unique()->numberBetween(1, 999999),
            'name' => ['ar' => 'قطعة', 'en' => 'piece'],
            'allows_fractions' => false,
        ];
    }

    public function fractional(): static
    {
        return $this->state(['allows_fractions' => true, 'name' => ['ar' => 'كغ', 'en' => 'kg']]);
    }
}
