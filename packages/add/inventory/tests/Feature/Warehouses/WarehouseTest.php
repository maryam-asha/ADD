<?php

namespace Add\Inventory\Tests\Feature\Warehouses;

use Add\Inventory\Authorization\Abilities;
use Add\Inventory\Catalog\Models\Location;
use Add\Inventory\Integrations\OwnLocationResolver;
use Add\Inventory\Stock\Enums\IssueReason;
use Add\Inventory\Stock\Services\StockService;
use Add\Inventory\Tests\Concerns\BuildsInventory;
use Add\Inventory\Tests\TestCase;
use Add\Inventory\Warehouses\Models\Warehouse;

class WarehouseTest extends TestCase
{
    use BuildsInventory;

    private const ALL = [Abilities::WAREHOUSES_VIEW, Abilities::WAREHOUSES_MANAGE];

    public function test_store_creates_a_warehouse_and_returns_it(): void
    {
        $manager = $this->actingAsWith(self::ALL);
        $branch = Location::factory()->create();

        $this->postJson('/api/inventory/warehouses', [
            'name' => ['ar' => 'المطبخ', 'en' => 'Kitchen'],
            'manager_id' => $manager->id,
            'address' => 'Aleppo, Al-Aziziyah',
            'location_url' => 'https://maps.example.test/kitchen',
            'notes' => 'Cold room at the back',
            'place' => $this->at($branch),
            'item_scope' => 'all',
            'allows_sales' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name.en', 'Kitchen')
            ->assertJsonPath('data.item_scope', 'all')
            ->assertJsonPath('data.allows_sales', true)
            ->assertJsonPath('data.is_primary', false)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.manager.id', $manager->id)
            ->assertJsonPath('data.place.id', $branch->id);
    }

    public function test_selected_scope_requires_items_and_only_stock_items(): void
    {
        $this->actingAsWith(self::ALL);
        $stock = $this->stockItem();
        $asset = $this->assetItem();
        $payload = ['name' => ['ar' => 'الكافيه'], 'item_scope' => 'selected'];

        $this->postJson('/api/inventory/warehouses', [...$payload, 'item_ids' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('item_ids');
        $this->postJson('/api/inventory/warehouses', [...$payload, 'item_ids' => [$asset->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('item_ids.0');

        $this->postJson('/api/inventory/warehouses', [...$payload, 'item_ids' => [$stock->id]])
            ->assertCreated()
            ->assertJsonPath('data.item_scope', 'selected')
            ->assertJsonPath('data.items.0.id', $stock->id);
    }

    public function test_making_one_primary_clears_the_other_primary_of_the_same_place(): void
    {
        $this->actingAsWith(self::ALL);
        $branch = Location::factory()->create();
        $first = $this->warehouse(['place_type' => OwnLocationResolver::TYPE, 'place_id' => $branch->id, 'is_primary' => true]);

        $this->postJson('/api/inventory/warehouses', ['name' => ['ar' => 'ثاني'], 'place' => $this->at($branch), 'is_primary' => true])
            ->assertCreated()->assertJsonPath('data.is_primary', true);

        $this->assertFalse($first->refresh()->is_primary);
    }

    public function test_primary_needs_a_place_and_sales(): void
    {
        $this->actingAsWith(self::ALL);
        $branch = Location::factory()->create();

        $this->postJson('/api/inventory/warehouses', ['name' => ['ar' => 'بلا مكان'], 'is_primary' => true])
            ->assertUnprocessable();
        $this->postJson('/api/inventory/warehouses', ['name' => ['ar' => 'بلا بيع'], 'place' => $this->at($branch), 'is_primary' => true, 'allows_sales' => false])
            ->assertUnprocessable();

        $this->assertDatabaseCount('inv_warehouses', 0);
    }

    public function test_place_type_outside_config_is_refused(): void
    {
        $this->actingAsWith(self::ALL);
        config(['inventory.warehouses.place_types' => ['branch']]);

        $this->postJson('/api/inventory/warehouses', ['name' => ['ar' => 'خارجي'], 'place' => $this->at(Location::factory()->create())])
            ->assertUnprocessable()->assertJsonValidationErrors('place.type');
    }

    public function test_empty_warehouse_can_be_deactivated_and_deleted(): void
    {
        $this->actingAsWith(self::ALL);
        $warehouse = $this->warehouse();

        $this->patchJson("/api/inventory/warehouses/{$warehouse->id}", ['is_active' => false])
            ->assertOk()->assertJsonStructure(['message']);
        $this->assertFalse($warehouse->refresh()->is_active);

        $this->deleteJson("/api/inventory/warehouses/{$warehouse->id}")->assertNoContent();
        $this->assertDatabaseCount('inv_warehouses', 0);
    }

    public function test_index_filters_by_search_status_and_manager(): void
    {
        $manager = $this->actingAsWith(self::ALL);
        $kitchen = $this->warehouse(['name' => ['ar' => 'المطبخ', 'en' => 'Kitchen'], 'manager_id' => $manager->id]);
        $this->warehouse(['name' => ['ar' => 'الخارجي', 'en' => 'External'], 'is_active' => false]);

        $this->getJson('/api/inventory/warehouses?search=Kitch')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $kitchen->id);
        $this->getJson('/api/inventory/warehouses?active=0')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name.en', 'External');
        $this->getJson("/api/inventory/warehouses?manager_id={$manager->id}")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_cannot_deactivate_or_delete_while_holding_stock(): void
    {
        $this->actingAsWith(self::ALL);
        $warehouse = $this->warehouse();
        app(StockService::class)->receive($this->stockItem(), $this->in($warehouse), 5);

        $this->patchJson("/api/inventory/warehouses/{$warehouse->id}", ['is_active' => false])
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::warehouses.holds_stock'));
        $this->deleteJson("/api/inventory/warehouses/{$warehouse->id}")->assertUnprocessable();
        $this->assertTrue($warehouse->refresh()->is_active);
    }

    public function test_cannot_delete_with_history(): void
    {
        $this->actingAsWith(self::ALL);
        $warehouse = $this->warehouse();
        $item = $this->stockItem();
        app(StockService::class)->receive($item, $this->in($warehouse), 5);
        app(StockService::class)->issue($item, $this->in($warehouse), 5, IssueReason::InternalUse);

        $this->deleteJson("/api/inventory/warehouses/{$warehouse->id}")
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::warehouses.has_history'));
        $this->patchJson("/api/inventory/warehouses/{$warehouse->id}", ['is_active' => false])->assertOk();
    }

    public function test_narrowing_scope_refused_while_a_removed_item_holds_stock(): void
    {
        $this->actingAsWith(self::ALL);
        $warehouse = $this->warehouse();
        $held = $this->stockItem();
        $other = $this->stockItem();
        app(StockService::class)->receive($held, $this->in($warehouse), 2);

        $this->patchJson("/api/inventory/warehouses/{$warehouse->id}", ['item_scope' => 'selected', 'item_ids' => [$other->id]])
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::warehouses.scope_strands_stock'));
        $this->patchJson("/api/inventory/warehouses/{$warehouse->id}", ['item_scope' => 'selected', 'item_ids' => [$held->id, $other->id]])
            ->assertOk();
    }

    public function test_index_counts_items_with_balance(): void
    {
        $this->actingAsWith(self::ALL);
        $warehouse = $this->warehouse();
        app(StockService::class)->receive($this->stockItem(), $this->in($warehouse), 2);

        $this->getJson('/api/inventory/warehouses')->assertOk()->assertJsonPath('data.0.items_with_balance', 1);
    }

    public function test_viewing_needs_the_view_ability_and_writing_the_manage_one(): void
    {
        $this->actingAsWith([Abilities::WAREHOUSES_VIEW]);

        $this->getJson('/api/inventory/warehouses')->assertOk();
        $this->postJson('/api/inventory/warehouses', ['name' => ['ar' => 'x']])->assertForbidden();
        $this->assertSame(0, Warehouse::query()->count());
    }
}
