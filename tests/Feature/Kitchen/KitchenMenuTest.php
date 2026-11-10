<?php

namespace Tests\Feature\Kitchen;

use Add\Inventory\Stock\Services\StockService;
use Add\Inventory\Warehouses\Models\Warehouse;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Kitchen\Models\KitchenStore;
use App\Domain\Kitchen\Services\KitchenMenu;
use App\Domain\Retail\Models\SaleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The menu is the active sale items the kitchen store holds.
 */
class KitchenMenuTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Warehouse $kitchen;

    private Warehouse $main;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->main = Warehouse::factory()->create(['place_type' => 'branch', 'place_id' => $this->branch->id, 'is_primary' => true]);
        $this->kitchen = Warehouse::factory()->create(['place_type' => 'branch', 'place_id' => $this->branch->id]);
        KitchenStore::create(['branch_id' => $this->branch->id, 'warehouse_id' => $this->kitchen->id]);
    }

    public function test_lists_only_items_in_stock_in_the_kitchen_store(): void
    {
        $cola = SaleItem::factory()->create();
        $water = SaleItem::factory()->create();
        app(StockService::class)->receive($cola->item, $this->kitchen->asPlace(), 3, 0.5);
        app(StockService::class)->receive($water->item, $this->main->asPlace(), 5, 0.5);

        $menu = app(KitchenMenu::class)->for($this->branch->id);

        $this->assertCount(1, $menu);
        $this->assertSame($cola->id, $menu[0]['sale_item']->id);
        $this->assertSame(0, bccomp($menu[0]['available'], '3', 4));
    }

    public function test_inactive_sale_items_are_hidden(): void
    {
        $cola = SaleItem::factory()->create(['is_active' => false]);
        app(StockService::class)->receive($cola->item, $this->kitchen->asPlace(), 3, 0.5);

        $this->assertSame([], app(KitchenMenu::class)->for($this->branch->id));
    }

    public function test_no_kitchen_store_means_empty_menu(): void
    {
        $cola = SaleItem::factory()->create();
        app(StockService::class)->receive($cola->item, $this->main->asPlace(), 3, 0.5);

        $this->assertSame([], app(KitchenMenu::class)->for(Branch::factory()->create()->id));
    }
}
