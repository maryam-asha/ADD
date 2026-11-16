<?php

namespace Add\Inventory\Tests\Feature\Assets;

use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Assets\Models\AssetAssignment;
use Add\Inventory\Assets\Models\MaintenanceRecord;
use Add\Inventory\Assets\Models\MaintenanceSchedule;
use Add\Inventory\Authorization\Abilities;
use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Catalog\Models\Location;
use Add\Inventory\Integrations\OwnLocationResolver;
use Add\Inventory\Tests\Concerns\BuildsInventory;
use Add\Inventory\Tests\TestCase;

/**
 * The lists about assets narrow to a place — anywhere inside it, as a
 * branch takes all it holds — and to what matters on each list.
 */
class AssetListFiltersTest extends TestCase
{
    use BuildsInventory;

    private const ALL = [Abilities::ASSETS_VIEW, Abilities::REPORTS_VIEW];

    private Location $building;

    private Asset $inside;

    private Asset $outside;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsWith(self::ALL);

        $this->building = Location::factory()->create();
        $room = Location::factory()->in($this->building)->create();
        $this->inside = Asset::factory()->create(['location_id' => $room->id, 'code' => 'AST-IN', 'serial_number' => 'SN-IN']);
        $this->outside = Asset::factory()->create(['location_id' => Location::factory()->create()->id, 'code' => 'AST-OUT', 'serial_number' => 'SN-OUT']);
    }

    private function inBuilding(): string
    {
        return 'place[type]='.OwnLocationResolver::TYPE.'&place[id]='.$this->building->id;
    }

    private function holding(Asset $asset): AssetAssignment
    {
        return AssetAssignment::query()->create(['asset_id' => $asset->id, 'custodian_type' => 'user', 'custodian_id' => 1, 'assigned_at' => now()]);
    }

    private function inOtherCategory(Asset $asset): Category
    {
        $category = Category::factory()->create();
        $asset->item->update(['category_id' => $category->id]);

        return $category;
    }

    public function test_custody_filters_by_place_search_and_category(): void
    {
        $this->holding($this->inside);
        $this->holding($this->outside);
        $category = $this->inOtherCategory($this->outside);

        $this->getJson('/api/inventory/custody?'.$this->inBuilding())
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.asset.id', $this->inside->id);
        $this->getJson('/api/inventory/custody?search=sn-out')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.asset.id', $this->outside->id);
        $this->getJson("/api/inventory/custody?category_id={$category->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.asset.id', $this->outside->id);
    }

    public function test_maintenance_filters_by_place_type_dates_and_search(): void
    {
        MaintenanceRecord::query()->create(['asset_id' => $this->inside->id, 'type' => 'preventive', 'description' => 'Filter change', 'vendor' => 'Cool Co', 'opened_at' => '2026-11-01 10:00:00']);
        MaintenanceRecord::query()->create(['asset_id' => $this->outside->id, 'type' => 'corrective', 'description' => 'Broken leg', 'opened_at' => '2026-11-10 10:00:00']);

        $this->getJson('/api/inventory/maintenance?'.$this->inBuilding())
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.description', 'Filter change');
        $this->getJson('/api/inventory/maintenance?type=corrective')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.description', 'Broken leg');
        $this->getJson('/api/inventory/maintenance?from=2026-11-05&to=2026-11-30')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.description', 'Broken leg');
        $this->getJson('/api/inventory/maintenance?search=cool')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.description', 'Filter change');
        $this->getJson('/api/inventory/maintenance?search=AST-OUT')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.description', 'Broken leg');
    }

    public function test_due_schedules_filter_by_place(): void
    {
        foreach ([$this->inside, $this->outside] as $asset) {
            MaintenanceSchedule::query()->create(['asset_id' => $asset->id, 'title' => 'Service', 'interval_months' => 6, 'next_due_on' => now()->toDateString()]);
        }

        $this->getJson('/api/inventory/maintenance-schedules/due')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/inventory/maintenance-schedules/due?'.$this->inBuilding())
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.asset.id', $this->inside->id);
    }

    public function test_asset_register_filters_by_place_and_category(): void
    {
        $this->inside->update(['purchase_cost' => 100, 'currency' => 'USD']);
        $this->outside->update(['purchase_cost' => 40, 'currency' => 'USD']);
        $category = $this->inOtherCategory($this->outside);

        $this->getJson('/api/inventory/reports/asset-register?'.$this->inBuilding())
            ->assertOk()->assertJsonCount(1, 'data.assets')->assertJsonPath('data.assets.0.code', 'AST-IN')
            ->assertJsonPath('data.totals.0.cost', '100');
        $this->getJson("/api/inventory/reports/asset-register?category_id={$category->id}")
            ->assertOk()->assertJsonCount(1, 'data.assets')->assertJsonPath('data.assets.0.code', 'AST-OUT');
    }

    public function test_an_unknown_place_lists_nothing(): void
    {
        $this->holding($this->inside);
        $nowhere = 'place[type]='.OwnLocationResolver::TYPE.'&place[id]=999999';

        $this->getJson('/api/inventory/custody?'.$nowhere)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/inventory/maintenance?'.$nowhere)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/inventory/reports/asset-register?'.$nowhere)->assertOk()->assertJsonCount(0, 'data.assets');
    }
}
