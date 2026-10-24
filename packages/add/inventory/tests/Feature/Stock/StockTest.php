<?php

namespace Add\Inventory\Tests\Feature\Stock;

use Add\Inventory\Authorization\Abilities;
use Add\Inventory\Catalog\Models\Location;
use Add\Inventory\Stock\Enums\IssueReason;
use Add\Inventory\Stock\Models\StockBalance;
use Add\Inventory\Stock\Models\StockMovement;
use Add\Inventory\Stock\Services\InsufficientStockException;
use Add\Inventory\Stock\Services\StockService;
use Add\Inventory\Support\Decimal;
use Add\Inventory\Support\Place;
use Add\Inventory\Tests\Concerns\BuildsInventory;
use Add\Inventory\Tests\TestCase;
use LogicException;

class StockTest extends TestCase
{
    use BuildsInventory;

    private const ALL = [
        Abilities::STOCK_VIEW,
        Abilities::STOCK_RECEIVE,
        Abilities::STOCK_ISSUE,
        Abilities::STOCK_TRANSFER,
        Abilities::STOCK_ADJUST,
    ];

    public function test_receipts_average_the_cost_and_issues_leave_at_the_average(): void
    {
        $this->actingAsWith(self::ALL);
        $paper = $this->stockItem();
        $store = Location::factory()->create();

        $this->postJson('/api/inventory/stock/receive', ['item_id' => $paper->id, 'location' => $this->at($store), 'quantity' => 10, 'unit_cost' => 2])
            ->assertCreated()->assertJsonPath('data.average_cost_after', '2');
        $this->postJson('/api/inventory/stock/receive', ['item_id' => $paper->id, 'location' => $this->at($store), 'quantity' => 10, 'unit_cost' => 4])
            ->assertCreated()->assertJsonPath('data.average_cost_after', '3');

        $this->postJson('/api/inventory/stock/issue', ['item_id' => $paper->id, 'location' => $this->at($store), 'quantity' => 5, 'reason' => 'internal_use'])
            ->assertCreated()
            ->assertJsonPath('data.quantity', '-5')
            ->assertJsonPath('data.unit_cost', '3')
            ->assertJsonPath('data.quantity_after', '15')
            ->assertJsonPath('data.reason', 'internal_use');

        $this->getJson('/api/inventory/stock/balances')
            ->assertOk()
            ->assertJsonPath('data.0.quantity', '15')
            ->assertJsonPath('data.0.average_cost', '3')
            ->assertJsonPath('data.0.value', '45');
    }

    public function test_stock_never_goes_below_zero(): void
    {
        $this->actingAsWith(self::ALL);
        $paper = $this->stockItem();
        $store = Location::factory()->create();
        app(StockService::class)->receive($paper, $this->place($store), 3);

        $this->postJson('/api/inventory/stock/issue', ['item_id' => $paper->id, 'location' => $this->at($store), 'quantity' => 4, 'reason' => 'damaged'])
            ->assertUnprocessable();

        $this->assertDatabaseCount('inv_stock_movements', 1);
        $this->assertSame('3', app(StockService::class)->onHand($paper, $this->place($store)));
    }

    public function test_hosts_issue_for_their_own_operations_with_a_source(): void
    {
        $paper = $this->stockItem();
        $at = $this->place();
        $stock = app(StockService::class);
        $stock->receive($paper, $at, 2);

        $movement = $stock->issue($paper, $at, 2, IssueReason::Sale, new Place('item_sale', 41));

        $this->assertSame('item_sale', $movement->source_type);
        $this->assertSame(41, $movement->source_id);

        $this->expectException(InsufficientStockException::class);
        $stock->issue($paper, $at, 1, IssueReason::Consumption);
    }

    public function test_manual_issues_cannot_claim_a_sale(): void
    {
        $this->actingAsWith(self::ALL);
        $paper = $this->stockItem();
        $store = Location::factory()->create();

        $this->postJson('/api/inventory/stock/issue', ['item_id' => $paper->id, 'location' => $this->at($store), 'quantity' => 1, 'reason' => 'sale'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_whole_units_refuse_fractions(): void
    {
        $this->actingAsWith(self::ALL);
        $store = Location::factory()->create();

        $this->postJson('/api/inventory/stock/receive', ['item_id' => $this->stockItem()->id, 'location' => $this->at($store), 'quantity' => 1.5])
            ->assertUnprocessable();
        $this->postJson('/api/inventory/stock/receive', ['item_id' => $this->stockItem(fractional: true)->id, 'location' => $this->at($store), 'quantity' => 1.5])
            ->assertCreated()->assertJsonPath('data.quantity', '1.5');
    }

    public function test_assets_are_not_stock(): void
    {
        $this->actingAsWith(self::ALL);

        $this->postJson('/api/inventory/stock/receive', ['item_id' => $this->assetItem()->id, 'location' => $this->at(Location::factory()->create()), 'quantity' => 1])
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::stock.not_stock'));
    }

    public function test_a_transfer_moves_quantity_and_cost_together(): void
    {
        $this->actingAsWith(self::ALL);
        $paper = $this->stockItem();
        $store = Location::factory()->create();
        $desk = Location::factory()->create();
        app(StockService::class)->receive($paper, $this->place($store), 10, 2.5);

        $halves = $this->postJson('/api/inventory/stock/transfer', [
            'item_id' => $paper->id,
            'from' => $this->at($store),
            'to' => $this->at($desk),
            'quantity' => 4,
        ])->assertCreated()->json('data');

        $this->assertSame(['transfer_out', 'transfer_in'], array_column($halves, 'type'));
        $this->assertSame($halves[0]['transfer_id'], $halves[1]['transfer_id']);
        $this->assertSame('6', app(StockService::class)->onHand($paper, $this->place($store)));
        $this->assertSame('4', app(StockService::class)->onHand($paper, $this->place($desk)));
        $this->assertSame('2.5', StockBalance::query()->where('location_id', $desk->id)->firstOrFail()->average_cost);

        $this->postJson('/api/inventory/stock/transfer', ['item_id' => $paper->id, 'from' => $this->at($store), 'to' => $this->at($store), 'quantity' => 1])
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::stock.same_location'));
    }

    public function test_a_count_sets_the_balance_to_what_was_found(): void
    {
        $this->actingAsWith(self::ALL);
        $paper = $this->stockItem();
        $store = Location::factory()->create();
        app(StockService::class)->receive($paper, $this->place($store), 10, 1);

        $this->postJson('/api/inventory/stock/adjust', ['item_id' => $paper->id, 'location' => $this->at($store), 'counted' => 7])
            ->assertCreated()->assertJsonPath('data.quantity', '-3')->assertJsonPath('data.type', 'adjustment');
        $this->postJson('/api/inventory/stock/adjust', ['item_id' => $paper->id, 'location' => $this->at($store), 'counted' => 7])
            ->assertOk()->assertJsonPath('message', __('inventory::stock.count_matched'));
    }

    public function test_balances_of_a_place_include_the_places_inside_it(): void
    {
        $this->actingAsWith([...self::ALL, Abilities::CATALOG_VIEW]);
        $paper = $this->stockItem();
        $building = Location::factory()->create();
        $shelf = Location::factory()->in($building)->create();
        app(StockService::class)->receive($paper, $this->place($shelf), 5);
        app(StockService::class)->receive($paper, $this->place(), 9);

        $this->getJson("/api/inventory/stock/balances?location[type]=location&location[id]={$building->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.quantity', '5');
        $this->getJson("/api/inventory/stock/movements?location[type]=location&location[id]={$building->id}")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/inventory/items/{$paper->id}")
            ->assertOk()->assertJsonPath('data.on_hand', '14');
    }

    public function test_every_balance_equals_its_ledger(): void
    {
        $paper = $this->stockItem(fractional: true);
        $a = $this->place();
        $b = $this->place();
        $stock = app(StockService::class);
        $stock->receive($paper, $a, 12.5, 3);
        $stock->issue($paper, $a, 2.25, IssueReason::Consumption);
        $stock->transfer($paper, $a, $b, 4);
        $stock->adjust($paper, $b, 3.5);
        $stock->receive($paper, $b, 1, 7);

        foreach (StockBalance::query()->get() as $balance) {
            $ledger = Decimal::add('0', ...StockMovement::query()
                ->where('item_id', $balance->item_id)
                ->where('location_type', $balance->location_type)
                ->where('location_id', $balance->location_id)
                ->pluck('quantity')
                ->all());

            $this->assertSame(Decimal::trim($balance->quantity), Decimal::trim($ledger));
        }
    }

    public function test_the_ledger_is_append_only(): void
    {
        $movement = app(StockService::class)->receive($this->stockItem(), $this->place(), 1);

        $this->expectException(LogicException::class);
        $movement->update(['note' => 'changed']);
    }

    public function test_viewing_stock_does_not_allow_moving_it(): void
    {
        $this->actingAsWith([Abilities::STOCK_VIEW]);

        $this->postJson('/api/inventory/stock/receive', ['item_id' => $this->stockItem()->id, 'location' => $this->at(Location::factory()->create()), 'quantity' => 1])
            ->assertForbidden();
    }
}
