<?php

namespace Add\Inventory\Tests\Feature\Assets;

use Add\Inventory\Assets\Enums\AssetStatus;
use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Assets\Models\MaintenanceSchedule;
use Add\Inventory\Assets\Services\AssetService;
use Add\Inventory\Assets\Services\MaintenanceService;
use Add\Inventory\Authorization\Abilities;
use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Catalog\Models\Location;
use Add\Inventory\Events\CapitalAdditionRemoved;
use Add\Inventory\Events\DepreciationMonthClosed;
use Add\Inventory\Settings\SettingsStore;
use Add\Inventory\Tests\Concerns\BuildsInventory;
use Add\Inventory\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * What the final review of the settings branch found, each pinned: the
 * places a draft or intangible asset, a missed month or an unlocked
 * setting slipped through.
 */
class ReviewFindingsTest extends TestCase
{
    use BuildsInventory;

    private const ALL = [
        Abilities::CATALOG_VIEW, Abilities::CATALOG_MANAGE, Abilities::ASSETS_VIEW, Abilities::ASSETS_MANAGE,
        Abilities::ASSETS_MAINTENANCE, Abilities::REPORTS_VIEW,
    ];

    private function settings(array $values): void
    {
        app(SettingsStore::class)->save($values);
    }

    public function test_an_item_with_assets_keeps_its_tangibility_when_its_category_changes(): void
    {
        $this->actingAsWith(self::ALL);
        $this->settings(['assets.intangible' => true]);
        $tangible = Category::factory()->create();
        $intangible = Category::factory()->create(['intangible' => true]);
        $licence = Item::factory()->create(['category_id' => $intangible->id]);
        $chair = Item::factory()->create(['category_id' => $tangible->id]);
        Asset::factory()->create(['item_id' => $licence->id, 'location_type' => null, 'location_id' => null]);
        Asset::factory()->create(['item_id' => $chair->id]);

        $this->patchJson("/api/inventory/items/{$licence->id}", ['category_id' => $tangible->id])
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::catalog.intangible_has_assets'));
        $this->patchJson("/api/inventory/items/{$chair->id}", ['category_id' => $intangible->id])
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::catalog.intangible_has_assets'));
        $this->patchJson("/api/inventory/items/{$chair->id}", ['category_id' => Category::factory()->create()->id])->assertOk();
    }

    public function test_drafts_and_intangible_assets_take_no_maintenance_schedule(): void
    {
        $this->actingAsWith(self::ALL);
        $this->settings(['assets.intangible' => true, 'assets.drafts' => true]);
        $draft = Asset::factory()->create(['status' => AssetStatus::Draft, 'code' => null]);
        $licence = Asset::factory()->create([
            'item_id' => Item::factory()->create(['category_id' => Category::factory()->create(['intangible' => true])->id])->id,
            'location_type' => null, 'location_id' => null,
        ]);
        $schedule = ['title' => 'check', 'interval_months' => 6, 'next_due_on' => now()->addDay()->toDateString()];

        $this->postJson("/api/inventory/assets/{$draft->id}/maintenance-schedules", $schedule)
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::assets.draft'));
        $this->postJson("/api/inventory/assets/{$licence->id}/maintenance-schedules", $schedule)
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::assets.intangible_has_no_place'));

        // One made before either became so is not due.
        MaintenanceSchedule::query()->create(['asset_id' => $draft->id, ...$schedule, 'is_active' => true]);
        $this->assertSame(0, app(MaintenanceService::class)->due(7)->count());
    }

    public function test_a_null_or_missing_method_takes_the_first_enabled_one(): void
    {
        $this->actingAsWith(self::ALL);
        $payload = ['item_id' => $this->assetItem()->id, 'location' => $this->at(Location::factory()->create())];

        $this->postJson('/api/inventory/assets', [...$payload, 'depreciation_method' => null])
            ->assertCreated()->assertJsonPath('data.0.depreciation_method', 'straight_line');

        $this->settings(['assets.depreciation_methods' => ['declining_balance', 'straight_line']]);
        $this->postJson('/api/inventory/assets', $payload)->assertCreated()->assertJsonPath('data.0.depreciation_method', 'declining_balance');
        $this->postJson('/api/inventory/assets/batch', ['location' => $payload['location'], 'lines' => [['item_id' => $payload['item_id'], 'depreciation_method' => null]]])
            ->assertCreated()->assertJsonPath('data.0.depreciation_method', 'declining_balance');
    }

    public function test_a_missed_month_is_still_closed(): void
    {
        Event::fake([DepreciationMonthClosed::class]);

        $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00'));
        $this->artisan('inventory:run-daily')->assertSuccessful();
        $this->travelTo(CarbonImmutable::parse('2026-12-03 10:00'));
        $this->artisan('inventory:run-daily')->assertSuccessful();

        $closed = [];
        Event::assertDispatched(DepreciationMonthClosed::class, function (DepreciationMonthClosed $event) use (&$closed) {
            $closed[] = sprintf('%d-%02d', $event->year, $event->month);

            return true;
        });
        $this->assertSame(['2026-09', '2026-10', '2026-11'], $closed);
    }

    public function test_removing_an_addition_is_announced_and_a_closed_month_takes_none(): void
    {
        Event::fake([CapitalAdditionRemoved::class]);
        $this->actingAsWith(self::ALL);
        $this->settings(['assets.capital_additions' => true]);
        $asset = Asset::factory()->create(['purchase_cost' => 100, 'currency' => 'USD', 'purchased_on' => '2026-01-01']);
        $addition = $asset->additions()->create(['added_on' => '2026-02-01', 'amount' => 10, 'description' => 'x']);

        app(AssetService::class)->removeCapital($addition);
        Event::assertDispatched(CapitalAdditionRemoved::class, fn (CapitalAdditionRemoved $event) => $event->assetId === $asset->id && $event->amount === '10');

        DB::table('inv_sequences')->insert(['name' => 'depreciation.closed', 'value' => 202602]);
        $this->postJson("/api/inventory/assets/{$asset->id}/additions", ['added_on' => '2026-02-15', 'amount' => 5, 'description' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('added_on');
        $this->postJson("/api/inventory/assets/{$asset->id}/additions", ['added_on' => '2026-03-01', 'amount' => 5, 'description' => 'x'])
            ->assertCreated();
    }

    public function test_the_settings_page_shows_what_cannot_be_turned_off(): void
    {
        $this->settings(['assets.drafts' => true, 'assets.depreciation_methods' => ['straight_line', 'declining_balance']]);
        Asset::factory()->create(['status' => AssetStatus::Draft, 'code' => null, 'depreciation_method' => 'declining_balance']);

        $rows = collect(app(SettingsStore::class)->describe())->keyBy('key');

        $this->assertTrue($rows['assets.drafts']['locked']);
        $this->assertSame(__('inventory::settings.locked_drafts_exist'), $rows['assets.drafts']['locked_reason']);
        $this->assertFalse($rows['assets.capital_additions']['locked']);
        $this->assertFalse($rows['assets.depreciation_methods']['locked']);
        $this->assertSame(['declining_balance'], $rows['assets.depreciation_methods']['locked_options']);
    }

    public function test_the_asset_register_loads_in_a_fixed_number_of_queries(): void
    {
        $this->actingAsWith(self::ALL);
        // One place: a place's label is looked up once per distinct place.
        $room = Location::factory()->create();
        Asset::factory()->count(15)->create(['location_id' => $room->id, 'purchase_cost' => 100, 'currency' => 'USD', 'purchased_on' => '2026-01-01', 'useful_life_months' => 10]);

        DB::enableQueryLog();
        $this->getJson('/api/inventory/reports/asset-register')->assertOk();

        $this->assertLessThan(10, count(DB::getQueryLog()));
    }
}
