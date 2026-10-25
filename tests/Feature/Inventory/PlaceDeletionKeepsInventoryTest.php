<?php

namespace Tests\Feature\Inventory;

use Add\Inventory\Assets\Enums\DisposalMethod;
use Add\Inventory\Assets\Services\AssetService;
use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Stock\Services\StockService;
use Add\Inventory\Support\Place;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Floor;
use App\Domain\Foundation\Models\Space;
use App\Domain\Foundation\Models\Zone;
use App\Domain\Identity\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Inventory stores its place as a type and an id, not a foreign key, so a
 * delete must refuse a place still holding it — itself or one the delete
 * takes with it — or its assets and stock would point at nothing.
 */
class PlaceDeletionKeepsInventoryTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Building $building;

    private Floor $floor;

    private Zone $zone;

    private Space $space;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $this->branch = Branch::factory()->create();
        $this->building = Building::factory()->create(['branch_id' => $this->branch->id]);
        $this->floor = Floor::factory()->create(['building_id' => $this->building->id]);
        $this->zone = Zone::factory()->create(['floor_id' => $this->floor->id]);
        $this->space = Space::factory()->create(['building_id' => $this->building->id, 'zone_id' => $this->zone->id]);
    }

    private function assetIn(Place $place)
    {
        return app(AssetService::class)->register(Item::factory()->create(), $place)->first();
    }

    private function stockIn(Place $place, int $quantity): Item
    {
        $item = Item::factory()->create(['category_id' => Category::factory()->stock()->create()->id]);
        app(StockService::class)->receive($item, $place, $quantity);

        return $item;
    }

    public function test_a_space_with_an_asset_in_it_is_not_deleted(): void
    {
        $this->assetIn(new Place('space', $this->space->id));

        $this->deleteJson("/api/v1/admin/spaces/{$this->space->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', __('api.admin.place_holds_inventory'));

        $this->assertModelExists($this->space);
    }

    public function test_a_building_or_branch_is_refused_for_stock_anywhere_inside_it(): void
    {
        $this->stockIn(new Place('space', $this->space->id), 3);

        $this->deleteJson("/api/v1/admin/buildings/{$this->building->id}")->assertUnprocessable();
        $this->deleteJson("/api/v1/admin/branches/{$this->branch->id}")->assertUnprocessable();

        $this->assertModelExists($this->building);
        $this->assertModelExists($this->branch);
    }

    public function test_a_zone_or_floor_goes_when_only_its_spaces_hold_inventory(): void
    {
        // Their spaces stay (unzoned), and the asset with them.
        $this->assetIn(new Place('space', $this->space->id));

        $this->deleteJson("/api/v1/admin/zones/{$this->zone->id}")->assertNoContent();
        $this->deleteJson("/api/v1/admin/floors/{$this->floor->id}")->assertNoContent();

        $this->assertModelExists($this->space);
    }

    public function test_a_floor_is_refused_for_inventory_on_one_of_its_zones(): void
    {
        $this->stockIn(new Place('zone', $this->zone->id), 1);

        $this->deleteJson("/api/v1/admin/floors/{$this->floor->id}")->assertUnprocessable();
    }

    public function test_disposed_assets_and_empty_stock_do_not_hold_a_place(): void
    {
        $place = new Place('space', $this->space->id);
        $asset = $this->assetIn($place);
        app(AssetService::class)->dispose($asset, DisposalMethod::Scrapped, now());
        $item = $this->stockIn($place, 2);
        app(StockService::class)->adjust($item, $place, 0);

        $this->deleteJson("/api/v1/admin/spaces/{$this->space->id}")->assertNoContent();
    }
}
