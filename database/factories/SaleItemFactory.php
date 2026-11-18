<?php

namespace Database\Factories;

use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Catalog\Models\Item;
use App\Domain\Retail\Models\SaleItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleItem>
 */
class SaleItemFactory extends Factory
{
    protected $model = SaleItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inventory_item_id' => Item::factory()->state(['category_id' => Category::factory()->stock()]),
            'price_points' => 20,
            'is_active' => true,
        ];
    }
}
