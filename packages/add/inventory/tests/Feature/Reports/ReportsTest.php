<?php

namespace Add\Inventory\Tests\Feature\Reports;

use Add\Inventory\Assets\Services\AssetService;
use Add\Inventory\Authorization\Abilities;
use Add\Inventory\Stock\Services\StockService;
use Add\Inventory\Tests\Concerns\BuildsInventory;
use Add\Inventory\Tests\TestCase;

class ReportsTest extends TestCase
{
    use BuildsInventory;

    public function test_stock_valuation_per_warehouse_at_average_cost(): void
    {
        $this->actingAsWith([Abilities::REPORTS_VIEW]);
        $store = $this->warehouse();
        $desk = $this->warehouse();
        $stock = app(StockService::class);
        $stock->receive($this->stockItem(), $this->in($store), 10, 1.25);
        $stock->receive($this->stockItem(), $this->in($store), 2, 10);
        $stock->receive($this->stockItem(), $this->in($desk), 3, 1);

        $this->getJson('/api/inventory/reports/stock-valuation')
            ->assertOk()
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.total', '35.5')
            ->assertJsonPath('data.warehouses.0.warehouse.id', $store->id)
            ->assertJsonPath('data.warehouses.0.items', 2)
            ->assertJsonPath('data.warehouses.0.value', '32.5');

        $this->getJson("/api/inventory/reports/stock-valuation?warehouse_id={$desk->id}")
            ->assertOk()->assertJsonPath('data.total', '3')->assertJsonCount(1, 'data.warehouses');
    }

    public function test_asset_register_totals_each_currency_apart(): void
    {
        $this->actingAsWith([Abilities::REPORTS_VIEW]);
        $this->travelTo('2026-07-01');
        $assets = app(AssetService::class);
        $assets->register($this->assetItem(), $this->place(), ['purchased_on' => '2026-01-01', 'purchase_cost' => 1200, 'currency' => 'USD', 'useful_life_months' => 12]);
        $assets->register($this->assetItem(), $this->place(), ['purchase_cost' => 500000, 'currency' => 'SYP']);

        $totals = collect($this->getJson('/api/inventory/reports/asset-register')->assertOk()->json('data.totals'))->keyBy('currency');

        $this->assertSame(['currency' => 'USD', 'cost' => '1200', 'accumulated' => '600', 'book_value' => '600'], $totals['USD']);
        $this->assertSame('500000', $totals['SYP']['book_value']);
    }

    public function test_reports_need_their_ability(): void
    {
        $this->actingAsWith([Abilities::STOCK_VIEW, Abilities::ASSETS_VIEW]);

        $this->getJson('/api/inventory/reports/stock-valuation')->assertForbidden();
        $this->getJson('/api/inventory/reports/asset-register')->assertForbidden();
    }
}
