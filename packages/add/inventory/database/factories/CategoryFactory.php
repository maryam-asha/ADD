<?php

namespace Add\Inventory\Database\Factories;

use Add\Inventory\Catalog\Enums\ItemKind;
use Add\Inventory\Catalog\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->word());

        return [
            'kind' => ItemKind::Asset,
            'name' => ['ar' => $name, 'en' => $name],
            'show_to_members' => false,
            'order' => 0,
        ];
    }

    public function stock(): static
    {
        return $this->state(['kind' => ItemKind::Stock]);
    }

    public function under(Category $parent): static
    {
        return $this->state(['parent_id' => $parent->id, 'kind' => $parent->kind]);
    }
}
