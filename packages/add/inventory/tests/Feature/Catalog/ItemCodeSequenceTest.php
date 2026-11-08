<?php

namespace Add\Inventory\Tests\Feature\Catalog;

use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Catalog\Models\Unit;
use Add\Inventory\Catalog\Services\CatalogService;
use Add\Inventory\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A numbered item code comes from one counter of its own, not from the
 * item's id: config inventory.items.sku_prefix + the next number, padded
 * to sku_pad digits. Nothing a rolled-back save took is lost, and a code
 * already in use is stepped over.
 */
class ItemCodeSequenceTest extends TestCase
{
    private function make(array $data = []): Item
    {
        return app(CatalogService::class)->createItem([
            'name' => ['ar' => 'صنف'],
            'category_id' => Category::factory()->stock()->create()->id,
            'unit_id' => Unit::factory()->create()->id,
            ...$data,
        ]);
    }

    public function test_codes_follow_one_another_with_the_configured_characters(): void
    {
        config(['inventory.items.sku_prefix' => 'P-', 'inventory.items.sku_pad' => 3]);

        $this->assertSame('P-001', $this->make()->sku);
        $this->assertSame('P-002', $this->make()->sku);
    }

    public function test_the_number_is_the_counters_not_the_items_id(): void
    {
        // Two items coded some other way take ids 1 and 2.
        Item::factory()->stock()->count(2)->create();

        $this->assertSame('ITM-00001', $this->make()->sku);
    }

    public function test_a_save_that_rolls_back_takes_no_number(): void
    {
        $this->assertSame('ITM-00001', $this->make()->sku);

        try {
            DB::transaction(function () {
                $this->make();
                throw new RuntimeException('the rest of the save failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame('ITM-00002', $this->make()->sku);
    }

    public function test_the_count_starts_after_the_highest_code_already_there(): void
    {
        // Items numbered before the counter existed (from their id).
        Item::factory()->stock()->create(['sku' => 'ITM-00007']);
        Item::factory()->stock()->create(['sku' => 'OTHER-00090']);

        $this->assertSame('ITM-00008', $this->make()->sku);
    }

    public function test_a_code_typed_in_the_same_shape_is_stepped_over(): void
    {
        $this->assertSame('ITM-00001', $this->make()->sku);
        $this->make(['sku' => 'ITM-00002']);

        $this->assertSame('ITM-00003', $this->make()->sku);
    }

    public function test_a_deleted_items_number_is_not_given_again(): void
    {
        $this->make();
        $second = $this->make();
        app(CatalogService::class)->deleteItem($second);

        $this->assertSame('ITM-00003', $this->make()->sku);
    }
}
