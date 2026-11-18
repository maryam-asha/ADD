<?php

namespace Add\Inventory\Tests\Feature\Stock;

use Add\Inventory\Authorization\Abilities;
use Add\Inventory\Events\StockFellBelowMinimum;
use Add\Inventory\Stock\Enums\IssueReason;
use Add\Inventory\Stock\Models\StockMinimum;
use Add\Inventory\Stock\Services\StockService;
use Add\Inventory\Tests\Concerns\BuildsInventory;
use Add\Inventory\Tests\TestCase;
use Illuminate\Support\Facades\Event;

class StockMinimumTest extends TestCase
{
    use BuildsInventory;

    private const ALL = [Abilities::WAREHOUSES_VIEW, Abilities::WAREHOUSES_MANAGE];

    public function test_falling_below_minimum_alerts_once(): void
    {
        Event::fake([StockFellBelowMinimum::class]);
        $this->actingAsWith(self::ALL);
        $store = $this->warehouse();
        $item = $this->stockItem();
        $stock = app(StockService::class);
        $stock->receive($item, $this->in($store), 10);

        $this->putJson("/api/inventory/warehouses/{$store->id}/minimums", ['items' => [['item_id' => $item->id, 'minimum' => 5]]])
            ->assertOk()
            ->assertJsonPath('data.0.minimum', '5');

        $stock->issue($item, $this->in($store), 3, IssueReason::Consumption);
        Event::assertNotDispatched(StockFellBelowMinimum::class);

        $stock->issue($item, $this->in($store), 3, IssueReason::Consumption);
        Event::assertDispatchedTimes(StockFellBelowMinimum::class, 1);
        Event::assertDispatched(StockFellBelowMinimum::class, fn (StockFellBelowMinimum $e) => $e->item->is($item) && $e->warehouse->is($store) && $e->onHand === '4' && $e->minimum === '5');

        // Still below: no second warning.
        $stock->issue($item, $this->in($store), 1, IssueReason::Consumption);
        Event::assertDispatchedTimes(StockFellBelowMinimum::class, 1);
        $this->assertNotNull(StockMinimum::query()->first()->alerted_at);
    }

    public function test_restocking_above_minimum_rearms(): void
    {
        Event::fake([StockFellBelowMinimum::class]);
        $store = $this->warehouse();
        $item = $this->stockItem();
        StockMinimum::query()->create(['warehouse_id' => $store->id, 'item_id' => $item->id, 'minimum' => 5]);
        $stock = app(StockService::class);
        $stock->receive($item, $this->in($store), 6);

        $stock->issue($item, $this->in($store), 3, IssueReason::Consumption);
        Event::assertDispatchedTimes(StockFellBelowMinimum::class, 1);

        $stock->receive($item, $this->in($store), 10);
        $this->assertNull(StockMinimum::query()->first()->alerted_at);

        $stock->issue($item, $this->in($store), 12, IssueReason::Consumption);
        Event::assertDispatchedTimes(StockFellBelowMinimum::class, 2);
    }

    public function test_no_minimum_no_event(): void
    {
        Event::fake([StockFellBelowMinimum::class]);
        $store = $this->warehouse();
        $item = $this->stockItem();
        $stock = app(StockService::class);

        $stock->receive($item, $this->in($store), 2);
        $stock->issue($item, $this->in($store), 2, IssueReason::Consumption);

        Event::assertNotDispatched(StockFellBelowMinimum::class);
    }

    public function test_minimum_zero_removes_it(): void
    {
        Event::fake([StockFellBelowMinimum::class]);
        $this->actingAsWith(self::ALL);
        $store = $this->warehouse();
        $item = $this->stockItem();
        $url = "/api/inventory/warehouses/{$store->id}/minimums";

        $this->putJson($url, ['items' => [['item_id' => $item->id, 'minimum' => 5]]])->assertOk();
        $this->assertSame(1, StockMinimum::query()->count());

        $this->putJson($url, ['items' => [['item_id' => $item->id, 'minimum' => 0]]])
            ->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(0, StockMinimum::query()->count());

        $stock = app(StockService::class);
        $stock->receive($item, $this->in($store), 1);
        $stock->issue($item, $this->in($store), 1, IssueReason::Consumption);
        Event::assertNotDispatched(StockFellBelowMinimum::class);
    }

    public function test_minimums_are_gated_by_the_warehouse_abilities(): void
    {
        $this->actingAsWith([Abilities::WAREHOUSES_VIEW]);
        $store = $this->warehouse();
        $url = "/api/inventory/warehouses/{$store->id}/minimums";

        $this->getJson($url)->assertOk();
        $this->putJson($url, ['items' => []])->assertForbidden();
    }
}
