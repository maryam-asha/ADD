<?php

namespace Add\Inventory\Tests\Feature\Assets;

use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Assets\Services\DepreciationCalculator;
use Add\Inventory\Authorization\Abilities;
use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Catalog\Models\Location;
use Add\Inventory\Settings\SettingsStore;
use Add\Inventory\Tests\Concerns\BuildsInventory;
use Add\Inventory\Tests\TestCase;
use Carbon\CarbonImmutable;

/**
 * config inventory.assets.intangible — assets with no body (a software
 * licence): an asset category may be marked intangible, and its assets sit
 * nowhere, take no maintenance, and are amortized on a straight line with
 * no salvage.
 */
class IntangibleAssetTest extends TestCase
{
    use BuildsInventory;

    private const ALL = [Abilities::CATALOG_VIEW, Abilities::CATALOG_MANAGE, Abilities::ASSETS_VIEW, Abilities::ASSETS_MANAGE, Abilities::ASSETS_MAINTENANCE, Abilities::REPORTS_VIEW];

    private function licence(): Item
    {
        app(SettingsStore::class)->save(['assets.intangible' => true]);

        return Item::factory()->create(['category_id' => Category::factory()->create(['intangible' => true])->id]);
    }

    public function test_intangible_categories_need_the_setting(): void
    {
        $this->actingAsWith(self::ALL);
        $payload = ['name' => ['ar' => 'رخص'], 'kind' => 'asset', 'intangible' => true];

        $this->postJson('/api/inventory/categories', $payload)->assertUnprocessable()->assertJsonValidationErrors('intangible');

        app(SettingsStore::class)->save(['assets.intangible' => true]);
        $this->postJson('/api/inventory/categories', $payload)->assertCreated()->assertJsonPath('data.intangible', true);
        $this->postJson('/api/inventory/categories', [...$payload, 'kind' => 'stock'])->assertUnprocessable()->assertJsonValidationErrors('intangible');
        $this->getJson('/api/inventory/settings')->assertJsonPath('data.intangible_assets', true);
    }

    public function test_an_intangible_asset_has_no_place_and_cannot_be_moved_or_maintained(): void
    {
        $this->actingAsWith(self::ALL);
        $item = $this->licence();

        $this->postJson('/api/inventory/assets', ['item_id' => $item->id, 'location' => $this->at(Location::factory()->create())])
            ->assertUnprocessable()->assertJsonValidationErrors('location');
        $id = $this->postJson('/api/inventory/assets', ['item_id' => $item->id])
            ->assertCreated()->assertJsonPath('data.0.location', null)->assertJsonPath('data.0.intangible', true)->json('data.0.id');

        $this->postJson("/api/inventory/assets/{$id}/move", ['location' => $this->at(Location::factory()->create())])
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::assets.intangible_has_no_place'));
        $this->postJson("/api/inventory/assets/{$id}/maintenance", ['type' => 'corrective', 'description' => 'x'])
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::assets.intangible_has_no_place'));

        $this->getJson('/api/inventory/assets')->assertOk()->assertJsonPath('data.0.location', null);
        $this->getJson('/api/inventory/reports/asset-register')->assertOk();
    }

    public function test_a_tangible_asset_still_needs_its_place(): void
    {
        $this->actingAsWith(self::ALL);

        $this->postJson('/api/inventory/assets', ['item_id' => $this->assetItem()->id])
            ->assertUnprocessable()->assertJsonValidationErrors('location');
    }

    public function test_amortization_ignores_salvage(): void
    {
        $asset = Asset::factory()->create([
            'item_id' => $this->licence()->id, 'location_type' => null, 'location_id' => null,
            'purchase_cost' => 1200, 'salvage_value' => 600, 'useful_life_months' => 12, 'purchased_on' => '2026-01-01',
        ]);

        $this->assertSame('300', app(DepreciationCalculator::class)->for($asset->refresh(), CarbonImmutable::parse('2026-04-01'))['accumulated']);
    }

    public function test_intangible_cannot_be_turned_off_while_a_category_uses_it(): void
    {
        $this->licence();

        $this->expectExceptionMessage(__('inventory::settings.locked_intangible_exists'));
        app(SettingsStore::class)->save(['assets.intangible' => false]);
    }

    public function test_intangible_lines_in_a_batch_sit_nowhere(): void
    {
        $this->actingAsWith(self::ALL);
        $licence = $this->licence();
        $chair = $this->assetItem();

        $assets = $this->postJson('/api/inventory/assets/batch', [
            'location' => $this->at(Location::factory()->create()),
            'lines' => [['item_id' => $licence->id], ['item_id' => $chair->id]],
        ])->assertCreated()->json('data');
        $this->assertNull($assets[0]['location']);
        $this->assertNotNull($assets[1]['location']);

        $this->postJson('/api/inventory/assets/batch', ['lines' => [['item_id' => $licence->id, 'count' => 2]]])->assertCreated()->assertJsonCount(2, 'data');
        $this->postJson('/api/inventory/assets/batch', ['lines' => [['item_id' => $licence->id], ['item_id' => $chair->id]]])
            ->assertUnprocessable()->assertJsonValidationErrors('location');
    }

    public function test_a_categorys_intangible_flag_is_fixed_once_it_has_assets(): void
    {
        $this->actingAsWith(self::ALL);
        $item = $this->licence();
        $this->postJson('/api/inventory/assets', ['item_id' => $item->id])->assertCreated();

        $this->patchJson("/api/inventory/categories/{$item->category_id}", ['intangible' => false])
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::catalog.intangible_has_assets'));
    }
}
