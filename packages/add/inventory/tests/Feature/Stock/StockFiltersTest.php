<?php

namespace Add\Inventory\Tests\Feature\Stock;

use Add\Inventory\Authorization\Abilities;
use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Catalog\Models\Location;
use Add\Inventory\Integrations\OwnLocationResolver;
use Add\Inventory\Stock\Services\StockService;
use Add\Inventory\Tests\Concerns\BuildsInventory;
use Add\Inventory\Tests\TestCase;

/**
 * Operations narrow to a place (a branch) on either side of a transfer;
 * the ledger narrows by category and by a search on the item.
 */
class StockFiltersTest extends TestCase
{
    use BuildsInventory;

    private const ALL = [Abilities::STOCK_VIEW, Abilities::STOCK_RECEIVE, Abilities::STOCK_TRANSFER];

    public function test_operations_filter_by_place_on_either_side_of_a_transfer(): void
    {
        $this->actingAsWith(self::ALL);
        $branch = Location::factory()->create();
        $inside = $this->warehouse(['place_type' => OwnLocationResolver::TYPE, 'place_id' => Location::factory()->in($branch)->create()->id]);
        [$a, $b] = [$this->warehouse(), $this->warehouse()];
        $paper = $this->stockItem();

        app(StockService::class)->receive($paper, $this->in($a), 10, 1);
        $this->postJson('/api/inventory/stock/transfer', ['from_warehouse_id' => $a->id, 'to_warehouse_id' => $inside->id, 'lines' => [['item_id' => $paper->id, 'quantity' => 2]]])->assertCreated();
        $this->postJson('/api/inventory/stock/transfer', ['from_warehouse_id' => $a->id, 'to_warehouse_id' => $b->id, 'lines' => [['item_id' => $paper->id, 'quantity' => 1]]])->assertCreated();

        $place = 'place[type]='.OwnLocationResolver::TYPE.'&place[id]='.$branch->id;
        $this->getJson('/api/inventory/stock/operations?'.$place)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.kind', 'transfer')->assertJsonPath('data.0.to_warehouse.id', $inside->id);
        $this->getJson('/api/inventory/stock/operations?place[type]='.OwnLocationResolver::TYPE.'&place[id]='.Location::factory()->create()->id)
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_movements_filter_by_category_and_search(): void
    {
        $this->actingAsWith(self::ALL);
        $store = $this->warehouse();
        $paper = $this->stockItem();
        $water = $this->stockItem();
        $drinks = Category::factory()->create(['kind' => 'stock']);
        $water->update(['category_id' => $drinks->id, 'sku' => 'WTR-500']);

        foreach ([$paper, $water] as $item) {
            app(StockService::class)->receive($item, $this->in($store), 3, 1);
        }

        $this->getJson("/api/inventory/stock/movements?category_id={$drinks->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.item.id', $water->id);
        $this->getJson('/api/inventory/stock/movements?search=wtr-5')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.item.id', $water->id);
    }
}
