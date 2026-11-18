<?php

namespace Add\Inventory\Database\Factories;

use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Catalog\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(2, true));

        return [
            'category_id' => Category::factory(),
            'unit_id' => Unit::factory(),
            'sku' => 'SKU-'.fake()->unique()->numberBetween(1, 999999),
            'name' => ['ar' => $name, 'en' => $name],
            'is_active' => true,
        ];
    }

    public function stock(): static
    {
        return $this->state(['category_id' => Category::factory()->stock()]);
    }

    public function fractional(): static
    {
        return $this->state(['unit_id' => Unit::factory()->fractional()]);
    }
}
