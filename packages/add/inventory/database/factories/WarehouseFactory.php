<?php

namespace Add\Inventory\Database\Factories;

use Add\Inventory\Warehouses\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => ['ar' => $name, 'en' => $name],
        ];
    }
}
