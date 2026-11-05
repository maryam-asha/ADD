<?php

namespace Add\Inventory\Tests\Feature\Stock;

use Add\Inventory\Authorization\Abilities;
use Add\Inventory\Catalog\Models\Location;
use Add\Inventory\Integrations\OwnLocationResolver;
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
        $store = $this->warehouse();

        $this->postJson('/api/inventory/stock/receive', ['item_id' => $paper->id, 'warehouse_id' => $store->id, 'quantity' => 10, 'unit_cost' => 2])
            ->assertCreated()->assertJsonPath('data.average_cost_after', '2');
        $this->postJson('/api/inventory/stock/receive', ['item_id' => $paper->id, 'warehouse_id' => $store->id, 'quantity' => 10, 'unit_cost' => 4])
            ->assertCreated()->assertJsonPath('data.average_cost_after', '3');

        $this->postJson('/api/inventory/stock/issue', ['item_id' => $paper->id, 'warehouse_id' => $store->id, 'quantity' => 5, 'reason' => 'internal_use'])
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
        $store = $this->warehouse();
        app(StockService::class)->receive($paper, $this->in($store), 3);

        $this->postJson('/api/inventory/stock/issue', ['item_id' => $paper->id, 'warehouse_id' => $store->id, 'quantity' => 4, 'reason' => 'damaged'])
            ->assertUnprocessable();

        $this->assertDatabaseCount('inv_stock_movements', 1);
        $this->assertSame('3', app(StockService::class)->onHand($paper, $this->in($store)));
    }

    public function test_hosts_issue_for_their_own_operations_with_a_source(): void
    {
        $paper = $this->stockItem();
        $at = $this->in($this->warehouse());
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
        $store = $this->warehouse();

        $this->postJson('/api/inventory/stock/issue', ['item_id' => $paper->id, 'warehouse_id' => $store->id, 'quantity' => 1, 'reason' => 'sale'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_whole_units_refuse_fractions(): void
    {
        $this->actingAsWith(self::ALL);
        $store = $this->warehouse();

        $this->postJson('/api/inventory/stock/receive', ['item_id' => $this->stockItem()->id, 'warehouse_id' => $store->id, 'quantity' => 1.5])
            ->assertUnprocessable();
        $this->postJson('/api/inventory/stock/receive', ['item_id' => $this->stockItem(fractional: true)->id, 'warehouse_id' => $store->id, 'quantity' => 1.5])
            ->assertCreated()->assertJsonPath('data.quantity', '1.5');
    }

    public function test_assets_are_not_stock(): void
    {
        $this->actingAsWith(self::ALL);

        $this->postJson('/api/inventory/stock/receive', ['item_id' => $this->assetItem()->id, 'warehouse_id' => $this->warehouse()->id, 'quantity' => 1])
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::stock.not_stock'));
    }

    public function test_a_transfer_moves_quantity_and_cost_together(): void
    {
        $this->actingAsWith(self::ALL);
        $paper = $this->stockItem();
        $store = $this->warehouse();
        $desk = $this->warehouse();
        app(StockService::class)->receive($paper, $this->in($store), 10, 2.5);

        $halves = $this->postJson('/api/inventory/stock/transfer', [
            'item_id' => $paper->id,
            'from_warehouse_id' => $store->id,
            'to_warehouse_id' => $desk->id,
            'quantity' => 4,
        ])->assertCreated()->json('data');

        $this->assertSame(['transfer_out', 'transfer_in'], array_column($halves, 'type'));
        $this->assertSame($halves[0]['transfer_id'], $halves[1]['transfer_id']);
        $this->assertSame('6', app(StockService::class)->onHand($paper, $this->in($store)));
        $this->assertSame('4', app(StockService::class)->onHand($paper, $this->in($desk)));
        $this->assertSame('2.5', StockBalance::query()->where('location_id', $desk->id)->firstOrFail()->average_cost);

        $this->postJson('/api/inventory/stock/transfer', ['item_id' => $paper->id, 'from_warehouse_id' => $store->id, 'to_warehouse_id' => $store->id, 'quantity' => 1])
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::stock.same_warehouse'));
    }

    public function test_a_count_sets_the_balance_to_what_was_found(): void
    {
        $this->actingAsWith(self::ALL);
        $paper = $this->stockItem();
        $store = $this->warehouse();
        app(StockService::class)->receive($paper, $this->in($store), 10, 1);

        $this->postJson('/api/inventory/stock/adjust', ['item_id' => $paper->id, 'warehouse_id' => $store->id, 'counted' => 7])
            ->assertCreated()->assertJsonPath('data.quantity', '-3')->assertJsonPath('data.type', 'adjustment');
        $this->postJson('/api/inventory/stock/adjust', ['item_id' => $paper->id, 'warehouse_id' => $store->id, 'counted' => 7])
            ->assertOk()->assertJsonPath('message', __('inventory::stock.count_matched'));
    }

    public function test_balances_filter_by_warehouse_and_by_place(): void
    {
        $this->actingAsWith([...self::ALL, Abilities::CATALOG_VIEW]);
        $paper = $this->stockItem();
        $building = Location::factory()->create();
        $shelf = Location::factory()->in($building)->create();
        $inside = $this->warehouse(['place_type' => OwnLocationResolver::TYPE, 'place_id' => $shelf->id]);
        $outside = $this->warehouse();
        app(StockService::class)->receive($paper, $this->in($inside), 5);
        app(StockService::class)->receive($paper, $this->in($outside), 9);

        $this->getJson("/api/inventory/stock/balances?place[type]=location&place[id]={$building->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.quantity', '5');
        $this->getJson("/api/inventory/stock/balances?warehouse_id={$outside->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.quantity', '9');
        $this->getJson("/api/inventory/stock/movements?place[type]=location&place[id]={$building->id}")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/inventory/items/{$paper->id}")
            ->assertOk()->assertJsonPath('data.on_hand', '14');
    }

    public function test_movements_filter_by_actor_and_operation(): void
    {
        $actor = $this->actingAsWith(self::ALL);
        $paper = $this->stockItem();
        $store = $this->warehouse();
        $stock = app(StockService::class);
        $stock->receive($paper, $this->in($store), 5, meta: ['operation_id' => 'aaaaaaaa-0000-4000-8000-000000000001']);
        auth()->logout();
        $stock->receive($paper, $this->in($store), 1);
        $this->actingAs($actor);

        $this->getJson("/api/inventory/stock/movements?actor_id={$actor->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.operation_id', 'aaaaaaaa-0000-4000-8000-000000000001');
        $this->getJson('/api/inventory/stock/movements?operation_id=aaaaaaaa-0000-4000-8000-000000000001')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_resources_show_the_warehouse(): void
    {
        $this->actingAsWith(self::ALL);
        $store = $this->warehouse(['name' => ['ar' => 'المطبخ', 'en' => 'Kitchen']]);
        app(StockService::class)->receive($this->stockItem(), $this->in($store), 2);

        $balance = $this->getJson('/api/inventory/stock/balances')->assertOk()->json('data.0');
        $this->assertSame($store->id, $balance['warehouse']['id']);
        $this->assertSame('Kitchen', $balance['warehouse']['name']['en']);
        $this->assertArrayNotHasKey('location', $balance);
        $this->getJson('/api/inventory/stock/movements')->assertOk()->assertJsonPath('data.0.warehouse.id', $store->id);
    }

    public function test_stock_cannot_be_received_on_a_plain_place(): void
    {
        $this->expectExceptionMessage(__('inventory::stock.not_a_warehouse'));

        app(StockService::class)->receive($this->stockItem(), $this->place(), 1);
    }

    public function test_inactive_warehouse_takes_no_movement(): void
    {
        $this->expectExceptionMessage(__('inventory::stock.warehouse_inactive'));

        app(StockService::class)->receive($this->stockItem(), $this->in($this->warehouse(['is_active' => false])), 1);
    }

    public function test_incoming_stock_outside_scope_is_refused_but_outgoing_is_allowed(): void
    {
        $a = $this->stockItem();
        $b = $this->stockItem();
        $cafe = $this->warehouse(['item_scope' => 'selected']);
        $cafe->items()->attach($a);
        $stock = app(StockService::class);

        $stock->receive($a, $this->in($cafe), 3);
        $stock->issue($a, $this->in($cafe), 1, IssueReason::InternalUse);
        $this->assertSame('2', $stock->onHand($a, $this->in($cafe)));

        $this->expectExceptionMessage(__('inventory::stock.outside_scope', ['warehouse' => $cafe->translate('name')]));
        $stock->receive($b, $this->in($cafe), 1);
    }

    public function test_concurrent_style_issue_cannot_overdraw(): void
    {
        $paper = $this->stockItem();
        $store = $this->warehouse(['name' => ['ar' => 'المخزن', 'en' => 'Store room']]);
        $stock = app(StockService::class);
        $stock->receive($paper, $this->in($store), 2);
        $stock->issue($paper, $this->in($store), 1, IssueReason::Sale);

        try {
            $stock->issue($paper, $this->in($store), 2, IssueReason::Sale);
            $this->fail('An issue past the balance went through.');
        } catch (InsufficientStockException $e) {
            $this->assertStringContainsString($store->translate('name'), $e->getMessage());
        }

        $this->assertSame('1', $stock->onHand($paper, $this->in($store)));
    }

    public function test_every_balance_equals_its_ledger(): void
    {
        $paper = $this->stockItem(fractional: true);
        $a = $this->in($this->warehouse());
        $b = $this->in($this->warehouse());
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
        $movement = app(StockService::class)->receive($this->stockItem(), $this->in($this->warehouse()), 1);

        $this->expectException(LogicException::class);
        $movement->update(['note' => 'changed']);
    }

    public function test_viewing_stock_does_not_allow_moving_it(): void
    {
        $this->actingAsWith([Abilities::STOCK_VIEW]);

        $this->postJson('/api/inventory/stock/receive', ['item_id' => $this->stockItem()->id, 'warehouse_id' => $this->warehouse()->id, 'quantity' => 1])
            ->assertForbidden();
    }
}
