<?php

namespace Add\Inventory\Tests\Feature\Stock;

use Add\Inventory\Authorization\Abilities;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Catalog\Models\Unit;
use Add\Inventory\Stock\Models\StockMovement;
use Add\Inventory\Stock\Services\StockService;
use Add\Inventory\Tests\Concerns\BuildsInventory;
use Add\Inventory\Tests\TestCase;

class StockOperationsTest extends TestCase
{
    use BuildsInventory;

    private const ALL = [
        Abilities::STOCK_VIEW,
        Abilities::STOCK_RECEIVE,
        Abilities::STOCK_ISSUE,
        Abilities::STOCK_TRANSFER,
        Abilities::STOCK_ADJUST,
    ];

    public function test_receive_several_lines_shares_one_operation(): void
    {
        $this->actingAsWith(self::ALL);
        $store = $this->warehouse();
        [$water, $cups] = [$this->stockItem(), $this->stockItem()];

        $rows = $this->postJson('/api/inventory/stock/receive', [
            'warehouse_id' => $store->id,
            'lines' => [['item_id' => $water->id, 'quantity' => 10, 'unit_cost' => 1], ['item_id' => $cups->id, 'quantity' => 50]],
            'note' => 'Delivery',
        ])->assertCreated()->json('data');

        $this->assertCount(2, $rows);
        $this->assertNotNull($rows[0]['operation_id']);
        $this->assertSame($rows[0]['operation_id'], $rows[1]['operation_id']);
        $this->assertSame('10', app(StockService::class)->onHand($water, $this->in($store)));
        $this->assertSame('50', app(StockService::class)->onHand($cups, $this->in($store)));
    }

    public function test_receive_in_packs_converts_to_main_unit_and_cost(): void
    {
        $this->actingAsWith(self::ALL);
        $store = $this->warehouse();
        [$water, $box] = $this->itemWithBox(6);

        $this->postJson('/api/inventory/stock/receive', [
            'warehouse_id' => $store->id,
            'lines' => [['item_id' => $water->id, 'unit_id' => $box->id, 'quantity' => 2, 'unit_cost' => 12]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.0.quantity', '12')
            ->assertJsonPath('data.0.unit_cost', '2')
            ->assertJsonPath('data.0.entered.quantity', '2')
            ->assertJsonPath('data.0.entered.unit.id', $box->id);
    }

    public function test_main_unit_lines_record_nothing_entered(): void
    {
        $this->actingAsWith(self::ALL);
        $item = $this->stockItem();

        $this->postJson('/api/inventory/stock/receive', ['warehouse_id' => $this->warehouse()->id, 'lines' => [['item_id' => $item->id, 'unit_id' => $item->unit_id, 'quantity' => 3]]])
            ->assertCreated()->assertJsonPath('data.0.entered', null);
    }

    public function test_fractional_pack_into_whole_unit_is_refused(): void
    {
        $this->actingAsWith(self::ALL);
        $store = $this->warehouse();
        [$water, $box] = $this->itemWithBox(6);

        $this->postJson('/api/inventory/stock/receive', ['warehouse_id' => $store->id, 'lines' => [['item_id' => $water->id, 'unit_id' => $box->id, 'quantity' => 0.1]]])
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::stock.whole_quantity', ['unit' => $water->unit->translate('name')]));
        $this->postJson('/api/inventory/stock/receive', ['warehouse_id' => $store->id, 'lines' => [['item_id' => $water->id, 'unit_id' => $box->id, 'quantity' => 1.5]]])
            ->assertCreated()->assertJsonPath('data.0.quantity', '9');
    }

    public function test_one_failing_line_rolls_back_the_operation(): void
    {
        $this->actingAsWith(self::ALL);
        $store = $this->warehouse();
        [$a, $b] = [$this->stockItem(), $this->stockItem()];
        app(StockService::class)->receive($a, $this->in($store), 5);
        app(StockService::class)->receive($b, $this->in($store), 1);

        $this->postJson('/api/inventory/stock/issue', [
            'warehouse_id' => $store->id,
            'reason' => 'internal_use',
            'lines' => [['item_id' => $a->id, 'quantity' => 2], ['item_id' => $b->id, 'quantity' => 3]],
        ])->assertUnprocessable();

        $this->assertSame('5', app(StockService::class)->onHand($a, $this->in($store)));
        $this->assertSame(2, StockMovement::query()->count());
    }

    public function test_transfer_lines_between_warehouses(): void
    {
        $this->actingAsWith(self::ALL);
        [$store, $cafe] = [$this->warehouse(), $this->warehouse()];
        [$a, $b] = [$this->stockItem(), $this->stockItem()];
        app(StockService::class)->receive($a, $this->in($store), 5, 2);
        app(StockService::class)->receive($b, $this->in($store), 5, 3);

        $rows = $this->postJson('/api/inventory/stock/transfer', [
            'from_warehouse_id' => $store->id,
            'to_warehouse_id' => $cafe->id,
            'lines' => [['item_id' => $a->id, 'quantity' => 2], ['item_id' => $b->id, 'quantity' => 1]],
        ])->assertCreated()->json('data');

        $this->assertCount(4, $rows);
        $this->assertCount(1, array_unique(array_column($rows, 'operation_id')));
        $this->assertCount(2, array_unique(array_column($rows, 'transfer_id')));
        $this->assertSame('2', app(StockService::class)->onHand($a, $this->in($cafe)));
        $this->assertSame('1', app(StockService::class)->onHand($b, $this->in($cafe)));
    }

    public function test_adjust_lines_record_only_differences(): void
    {
        $this->actingAsWith(self::ALL);
        $store = $this->warehouse();
        [$a, $b] = [$this->stockItem(), $this->stockItem()];
        app(StockService::class)->receive($a, $this->in($store), 5);
        app(StockService::class)->receive($b, $this->in($store), 5);

        $this->postJson('/api/inventory/stock/adjust', ['warehouse_id' => $store->id, 'lines' => [['item_id' => $a->id, 'counted' => 4], ['item_id' => $b->id, 'counted' => 5]]])
            ->assertCreated()->assertJsonCount(1, 'data')->assertJsonPath('data.0.quantity', '-1');
        $this->postJson('/api/inventory/stock/adjust', ['warehouse_id' => $store->id, 'lines' => [['item_id' => $a->id, 'counted' => 4]]])
            ->assertOk()->assertJsonPath('message', __('inventory::stock.count_matched'));
    }

    public function test_adjust_counts_in_packs(): void
    {
        $this->actingAsWith(self::ALL);
        $store = $this->warehouse();
        [$water, $box] = $this->itemWithBox(6);

        $this->postJson('/api/inventory/stock/adjust', ['warehouse_id' => $store->id, 'lines' => [['item_id' => $water->id, 'unit_id' => $box->id, 'counted' => 2]]])
            ->assertCreated()->assertJsonPath('data.0.quantity', '12')->assertJsonPath('data.0.entered.quantity', '2');
    }

    public function test_unit_not_of_item_is_refused(): void
    {
        $this->actingAsWith(self::ALL);

        $this->postJson('/api/inventory/stock/receive', [
            'warehouse_id' => $this->warehouse()->id,
            'lines' => [['item_id' => $this->stockItem()->id, 'unit_id' => Unit::factory()->create()->id, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonPath('message', __('inventory::catalog.unit_not_of_item'));
    }

    public function test_lines_are_required(): void
    {
        $this->actingAsWith(self::ALL);

        $this->postJson('/api/inventory/stock/receive', ['warehouse_id' => $this->warehouse()->id, 'lines' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('lines');
    }

    /**
     * @return array{0: Item, 1: Unit}
     */
    private function itemWithBox(int $factor): array
    {
        $item = $this->stockItem();
        $box = Unit::factory()->create(['name' => ['ar' => 'علبة', 'en' => 'box']]);
        $item->otherUnits()->create(['unit_id' => $box->id, 'factor' => $factor]);

        return [$item, $box];
    }
}
