<?php

namespace Add\Inventory\Tests\Feature\Assets;

use Add\Inventory\Assets\Enums\DisposalMethod;
use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Assets\Services\AssetService;
use Add\Inventory\Authorization\Abilities;
use Add\Inventory\Catalog\Models\Location;
use Add\Inventory\Integrations\OwnLocationResolver;
use Add\Inventory\Tests\Concerns\BuildsInventory;
use Add\Inventory\Tests\TestCase;

/**
 * config inventory.assets.store_in_warehouses: an asset may sit in a
 * warehouse — bought, not yet handed out — and leave it by being moved.
 * Off by default, where nothing changes.
 */
class AssetsInWarehousesTest extends TestCase
{
    use BuildsInventory;

    private const ALL = [
        Abilities::ASSETS_VIEW,
        Abilities::ASSETS_MANAGE,
        Abilities::ASSETS_DISPOSE,
        Abilities::WAREHOUSES_VIEW,
        Abilities::WAREHOUSES_MANAGE,
    ];

    private function enable(): void
    {
        config(['inventory.assets.store_in_warehouses' => true]);
    }

    public function test_off_by_default_a_warehouse_is_no_place_for_an_asset(): void
    {
        $this->actingAsWith(self::ALL);

        $this->postJson('/api/inventory/assets', ['item_id' => $this->assetItem()->id, 'location' => ['type' => 'warehouse', 'id' => $this->warehouse()->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('location.type');
        $this->getJson('/api/inventory/settings')->assertOk()->assertJsonPath('data.assets_in_warehouses', false);
    }

    public function test_an_asset_is_registered_in_a_warehouse_and_handed_out_by_a_move(): void
    {
        $this->enable();
        $this->actingAsWith(self::ALL);
        $store = $this->warehouse(['name' => ['ar' => 'المستودع الخارجي', 'en' => 'External store']]);
        $room = Location::factory()->create();

        $asset = $this->postJson('/api/inventory/assets', ['item_id' => $this->assetItem()->id, 'location' => ['type' => 'warehouse', 'id' => $store->id]])
            ->assertCreated()
            ->assertJsonPath('data.0.location.type', 'warehouse')
            ->assertJsonPath('data.0.location.label', 'External store')
            ->json('data.0');

        $this->postJson("/api/inventory/assets/{$asset['id']}/move", ['location' => $this->at($room), 'note' => 'Handed out'])->assertCreated();
        $this->postJson("/api/inventory/assets/{$asset['id']}/move", ['location' => ['type' => 'warehouse', 'id' => $store->id], 'note' => 'Back to store'])->assertCreated();

        $this->assertSame('warehouse', Asset::query()->findOrFail($asset['id'])->location_type);
        $this->getJson('/api/inventory/settings')->assertOk()->assertJsonPath('data.assets_in_warehouses', true);
    }

    public function test_an_inactive_warehouse_or_one_whose_scope_leaves_the_item_out_is_refused(): void
    {
        $this->enable();
        $this->actingAsWith(self::ALL);
        $item = $this->assetItem();
        $closed = $this->warehouse(['is_active' => false]);
        $narrow = $this->warehouse(['item_scope' => 'selected']);
        $narrow->items()->attach($this->assetItem());

        $this->postJson('/api/inventory/assets', ['item_id' => $item->id, 'location' => ['type' => 'warehouse', 'id' => $closed->id]])->assertUnprocessable();
        $this->postJson('/api/inventory/assets', ['item_id' => $item->id, 'location' => ['type' => 'warehouse', 'id' => $narrow->id]])
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::stock.outside_scope', ['warehouse' => $narrow->translate('name')]));
    }

    public function test_a_warehouse_may_list_asset_items_in_its_scope(): void
    {
        $this->enable();
        $this->actingAsWith(self::ALL);
        $item = $this->assetItem();

        $this->postJson('/api/inventory/warehouses', ['name' => ['ar' => 'مستودع الأجهزة'], 'item_scope' => 'selected', 'item_ids' => [$item->id]])
            ->assertCreated()->assertJsonPath('data.items.0.id', $item->id);
    }

    public function test_a_warehouse_holding_an_asset_is_neither_deactivated_nor_deleted(): void
    {
        $this->enable();
        $this->actingAsWith(self::ALL);
        $store = $this->warehouse();
        $asset = app(AssetService::class)->register($this->assetItem(), $store->asPlace())->first();

        $this->patchJson("/api/inventory/warehouses/{$store->id}", ['is_active' => false])
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::warehouses.holds_assets'));
        $this->deleteJson("/api/inventory/warehouses/{$store->id}")->assertUnprocessable();

        // A disposed asset no longer holds it open, but still keeps it from deletion.
        app(AssetService::class)->dispose($asset, DisposalMethod::Scrapped, now());
        $this->patchJson("/api/inventory/warehouses/{$store->id}", ['is_active' => false])->assertOk();
        $this->deleteJson("/api/inventory/warehouses/{$store->id}")->assertUnprocessable();
    }

    public function test_narrowing_a_scope_past_an_asset_it_holds_is_refused(): void
    {
        $this->enable();
        $this->actingAsWith(self::ALL);
        $store = $this->warehouse();
        app(AssetService::class)->register($this->assetItem(), $store->asPlace());

        $this->patchJson("/api/inventory/warehouses/{$store->id}", ['item_scope' => 'selected', 'item_ids' => [$this->assetItem()->id]])
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::warehouses.scope_strands_stock'));
    }

    public function test_assets_are_found_by_their_warehouse_and_by_the_place_it_sits_in(): void
    {
        $this->enable();
        $this->actingAsWith(self::ALL);
        $branch = Location::factory()->create();
        $store = $this->warehouse(['place_type' => OwnLocationResolver::TYPE, 'place_id' => $branch->id]);
        $service = app(AssetService::class);
        $inStore = $service->register($this->assetItem(), $store->asPlace())->first();
        $elsewhere = $service->register($this->assetItem(), $this->place())->first();

        $this->getJson("/api/inventory/assets?location[type]=warehouse&location[id]={$store->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $inStore->id);
        $this->getJson("/api/inventory/assets?location[type]=location&location[id]={$branch->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $inStore->id);
        $this->assertNotSame($elsewhere->id, $inStore->id);
    }
}
