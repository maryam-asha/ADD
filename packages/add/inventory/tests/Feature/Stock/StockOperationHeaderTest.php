<?php

namespace Add\Inventory\Tests\Feature\Stock;

use Add\Inventory\Authorization\Abilities;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Inventory;
use Add\Inventory\Stock\Models\StockMovement;
use Add\Inventory\Stock\Services\StockService;
use Add\Inventory\Tests\Concerns\BuildsInventory;
use Add\Inventory\Tests\TestCase;
use Illuminate\Support\Carbon;

class StockOperationHeaderTest extends TestCase
{
    use BuildsInventory;

    protected function tearDown(): void
    {
        Inventory::resolveTimezoneUsing(null);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private const ALL = [
        Abilities::STOCK_VIEW,
        Abilities::STOCK_RECEIVE,
        Abilities::STOCK_ISSUE,
        Abilities::STOCK_TRANSFER,
        Abilities::STOCK_ADJUST,
    ];

    public function test_receive_records_its_operation_header_and_line_notes(): void
    {
        $actor = $this->actingAsWith(self::ALL);
        $store = $this->warehouse();
        $water = $this->stockItem();

        $operation = $this->postJson('/api/inventory/stock/receive', [
            'warehouse_id' => $store->id,
            'operation_date' => '2026-11-01',
            'reference' => 'INV-77',
            'note' => 'Supplier delivery',
            'attachment_url' => 'https://cdn.example.test/uploads/inv-77.pdf',
            'cost_method' => 'entered',
            'lines' => [['item_id' => $water->id, 'quantity' => 10, 'unit_cost' => 2, 'note' => 'Two cartons dented']],
        ])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'receive')
            ->assertJsonPath('data.operation_date', '2026-11-01')
            ->assertJsonPath('data.reference', 'INV-77')
            ->assertJsonPath('data.note', 'Supplier delivery')
            ->assertJsonPath('data.cost_method', 'entered')
            ->assertJsonPath('data.attachment_url', 'https://cdn.example.test/uploads/inv-77.pdf')
            ->assertJsonPath('data.warehouse.id', $store->id)
            ->assertJsonPath('data.actor.id', $actor->id)
            ->assertJsonPath('data.movements.0.line_note', 'Two cartons dented')
            // The ledger row keeps the reason too, for the ledger tab.
            ->assertJsonPath('data.movements.0.note', 'Supplier delivery')
            ->assertJsonPath('data.movements.0.quantity', '10')
            ->json('data');

        $this->assertSame($operation['id'], StockMovement::query()->sole()->operation_id);
    }

    public function test_the_attachment_may_be_the_hosts_relative_upload_path(): void
    {
        $this->actingAsWith(self::ALL);

        $this->postJson('/api/inventory/stock/receive', [
            'warehouse_id' => $this->warehouse()->id,
            'note' => 'x',
            'attachment_url' => '/storage/uploads/inventory/invoice.pdf',
            'lines' => [['item_id' => $this->stockItem()->id, 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('data.attachment_url', '/storage/uploads/inventory/invoice.pdf');
    }

    public function test_the_date_defaults_to_today_and_cannot_be_in_the_future(): void
    {
        $this->actingAsWith(self::ALL);
        $payload = ['warehouse_id' => $this->warehouse()->id, 'note' => 'x', 'lines' => [['item_id' => $this->stockItem()->id, 'quantity' => 1]]];

        $this->postJson('/api/inventory/stock/receive', [...$payload, 'operation_date' => Carbon::tomorrow()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('operation_date');
        $this->postJson('/api/inventory/stock/receive', $payload)
            ->assertCreated()->assertJsonPath('data.operation_date', Carbon::today()->toDateString());
    }

    public function test_a_reason_text_is_required_except_for_a_transfer(): void
    {
        $this->actingAsWith(self::ALL);
        [$from, $to] = [$this->warehouse(), $this->warehouse()];
        $item = $this->stockItem();
        app(StockService::class)->receive($item, $this->in($from), 5);
        $line = [['item_id' => $item->id, 'quantity' => 1]];

        $this->postJson('/api/inventory/stock/receive', ['warehouse_id' => $from->id, 'lines' => $line])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson('/api/inventory/stock/issue', ['warehouse_id' => $from->id, 'reason' => 'internal_use', 'lines' => $line])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson('/api/inventory/stock/adjust', ['warehouse_id' => $from->id, 'lines' => [['item_id' => $item->id, 'counted' => 2]]])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson('/api/inventory/stock/transfer', ['from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id, 'lines' => $line])->assertCreated();
    }

    public function test_today_is_the_hosts_day_not_utcs(): void
    {
        $this->actingAsWith(self::ALL);
        Inventory::resolveTimezoneUsing(fn () => 'Asia/Damascus');
        // 22:30 UTC is already 01:30 the next day in Damascus.
        Carbon::setTestNow(Carbon::parse('2026-11-07 22:30:00', 'UTC'));
        $payload = ['warehouse_id' => $this->warehouse()->id, 'note' => 'x', 'lines' => [['item_id' => $this->stockItem()->id, 'quantity' => 1]]];

        $this->postJson('/api/inventory/stock/receive', [...$payload, 'operation_date' => '2026-11-08'])
            ->assertCreated()->assertJsonPath('data.operation_date', '2026-11-08');
        $this->postJson('/api/inventory/stock/receive', $payload)
            ->assertCreated()->assertJsonPath('data.operation_date', '2026-11-08');
        $this->postJson('/api/inventory/stock/receive', [...$payload, 'operation_date' => '2026-11-09'])
            ->assertUnprocessable()->assertJsonValidationErrors('operation_date');
    }

    public function test_average_cost_needs_an_average_to_use(): void
    {
        $this->actingAsWith(self::ALL);
        $store = $this->warehouse();
        $fresh = $this->stockItem();

        $this->postJson('/api/inventory/stock/receive', ['warehouse_id' => $store->id, 'note' => 'x', 'cost_method' => 'average', 'lines' => [['item_id' => $fresh->id, 'quantity' => 5]]])
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::stock.no_average_cost', ['item' => $fresh->translate('name')]));
        $this->assertDatabaseCount('inv_stock_movements', 0);
    }

    public function test_entered_cost_needs_a_cost_per_line_and_average_cost_ignores_one(): void
    {
        $this->actingAsWith(self::ALL);
        $store = $this->warehouse();
        $item = $this->stockItem();
        app(StockService::class)->receive($item, $this->in($store), 10, 2);

        $this->postJson('/api/inventory/stock/receive', ['warehouse_id' => $store->id, 'note' => 'x', 'cost_method' => 'entered', 'lines' => [['item_id' => $item->id, 'quantity' => 5]]])
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.unit_cost');

        $this->postJson('/api/inventory/stock/receive', ['warehouse_id' => $store->id, 'note' => 'x', 'cost_method' => 'average', 'lines' => [['item_id' => $item->id, 'quantity' => 5, 'unit_cost' => 50]]])
            ->assertCreated()
            ->assertJsonPath('data.cost_method', 'average')
            ->assertJsonPath('data.movements.0.unit_cost', '2')
            ->assertJsonPath('data.movements.0.average_cost_after', '2');
    }

    public function test_a_write_off_is_its_own_kind(): void
    {
        $this->actingAsWith(self::ALL);
        $store = $this->warehouse();
        $item = $this->stockItem();
        app(StockService::class)->receive($item, $this->in($store), 5);
        $payload = ['warehouse_id' => $store->id, 'note' => 'x', 'lines' => [['item_id' => $item->id, 'quantity' => 1]]];

        $this->postJson('/api/inventory/stock/issue', [...$payload, 'reason' => 'damaged'])->assertCreated()->assertJsonPath('data.kind', 'write_off');
        $this->postJson('/api/inventory/stock/issue', [...$payload, 'reason' => 'internal_use'])->assertCreated()->assertJsonPath('data.kind', 'issue');
    }

    public function test_a_count_that_matched_records_no_operation(): void
    {
        $this->actingAsWith(self::ALL);
        $store = $this->warehouse();
        $item = $this->stockItem();
        app(StockService::class)->receive($item, $this->in($store), 5);

        $this->postJson('/api/inventory/stock/adjust', ['warehouse_id' => $store->id, 'note' => 'Monthly count', 'lines' => [['item_id' => $item->id, 'counted' => 5]]])
            ->assertOk()->assertJsonPath('message', __('inventory::stock.count_matched'));
        $this->assertDatabaseCount('inv_stock_operations', 0);
    }

    public function test_operations_are_listed_newest_date_first_with_filters(): void
    {
        $actor = $this->actingAsWith(self::ALL);
        [$store, $cafe, $other] = [$this->warehouse(), $this->warehouse(), $this->warehouse()];
        [$a, $b] = [$this->stockItem(), $this->stockItem()];
        $receipt = $this->postJson('/api/inventory/stock/receive', ['warehouse_id' => $store->id, 'operation_date' => '2026-10-01', 'note' => 'x', 'lines' => [['item_id' => $a->id, 'quantity' => 5], ['item_id' => $b->id, 'quantity' => 5]]])->json('data.id');
        $transfer = $this->postJson('/api/inventory/stock/transfer', ['from_warehouse_id' => $store->id, 'to_warehouse_id' => $cafe->id, 'operation_date' => '2026-10-05', 'lines' => [['item_id' => $a->id, 'quantity' => 1]]])->json('data.id');
        $this->postJson('/api/inventory/stock/receive', ['warehouse_id' => $other->id, 'operation_date' => '2026-10-03', 'note' => 'x', 'lines' => [['item_id' => $a->id, 'quantity' => 1]]]);

        $this->getJson("/api/inventory/stock/operations?warehouse_id={$store->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $transfer)
            ->assertJsonPath('data.0.to_warehouse.id', $cafe->id)
            ->assertJsonPath('data.1.id', $receipt)
            ->assertJsonPath('data.1.lines_count', 2);
        // A transfer belongs to the warehouse it arrives in too.
        $this->getJson("/api/inventory/stock/operations?warehouse_id={$cafe->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/inventory/stock/operations?kind=transfer')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/inventory/stock/operations?from=2026-10-02&to=2026-10-04')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/inventory/stock/operations?actor_id={$actor->id}")->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_an_operation_shows_its_lines(): void
    {
        $this->actingAsWith(self::ALL);
        $store = $this->warehouse();
        $id = $this->postJson('/api/inventory/stock/receive', ['warehouse_id' => $store->id, 'note' => 'x', 'lines' => [['item_id' => $this->stockItem()->id, 'quantity' => 2], ['item_id' => $this->stockItem()->id, 'quantity' => 3]]])->json('data.id');

        $this->getJson("/api/inventory/stock/operations/{$id}")->assertOk()->assertJsonCount(2, 'data.movements')->assertJsonPath('data.id', $id);
    }

    public function test_balances_can_be_searched_by_item_name_code_or_barcode(): void
    {
        $this->actingAsWith(self::ALL);
        $store = $this->warehouse();
        $water = Item::factory()->stock()->create(['name' => ['ar' => 'مياه', 'en' => 'Water'], 'sku' => 'ITM-W', 'barcode' => '6291041500213']);
        app(StockService::class)->receive($water, $this->in($store), 2);
        app(StockService::class)->receive($this->stockItem(), $this->in($store), 2);

        foreach (['Wat', 'ITM-W', '62910415'] as $term) {
            $this->getJson("/api/inventory/stock/balances?warehouse_id={$store->id}&search={$term}")
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.item.id', $water->id);
        }
    }
}
