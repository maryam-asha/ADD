<?php

namespace Add\Inventory\Tests\Feature\Settings;

use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Catalog\Services\ItemCodeSequence;
use Add\Inventory\Settings\SettingsStore;
use Add\Inventory\Stock\Services\StockService;
use Add\Inventory\Support\InventoryException;
use Add\Inventory\Tests\Concerns\BuildsInventory;
use Add\Inventory\Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Settings saved from the dashboard sit in inv_settings and override the
 * config file; one that data depends on is locked once that data exists.
 */
class SettingsStoreTest extends TestCase
{
    use BuildsInventory;

    private function store(): SettingsStore
    {
        return app(SettingsStore::class);
    }

    public function test_a_saved_value_overrides_the_config_file(): void
    {
        $this->store()->save(['items.sku_prefix' => 'P-']);
        $this->assertSame('P-', config('inventory.items.sku_prefix'));

        // A new request: the file's value again, then the overlay on top.
        config(['inventory.items.sku_prefix' => 'ITM-']);
        $this->store()->apply();
        $this->assertSame('P-', config('inventory.items.sku_prefix'));
    }

    public function test_boot_survives_a_missing_table(): void
    {
        Schema::drop('inv_settings');

        $this->store()->apply();
        $this->assertSame('ITM-', config('inventory.items.sku_prefix'));
    }

    public function test_an_unknown_key_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->store()->save(['user_model' => 'X']);
    }

    public function test_an_invalid_value_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->store()->save(['items.sku_mode' => 'sometimes']);
    }

    public function test_code_settings_lock_once_data_depends_on_them(): void
    {
        Asset::factory()->create();

        try {
            $this->store()->save(['assets.code_prefix' => 'A-']);
            $this->fail('a locked setting was saved');
        } catch (InventoryException $e) {
            $this->assertSame(__('inventory::settings.locked_assets_exist'), $e->getMessage());
        }

        $this->assertSame('AST-', config('inventory.assets.code_prefix'));
        $this->store()->save(['assets.warranty_lead_days' => 10]);
        $this->assertSame(10, config('inventory.assets.warranty_lead_days'));
    }

    public function test_saving_the_same_value_is_not_a_change(): void
    {
        Asset::factory()->create();

        $this->store()->save(['assets.code_prefix' => 'AST-']);
        $this->assertSame('AST-', config('inventory.assets.code_prefix'));
    }

    public function test_item_numbering_locks_after_the_first_numbered_item(): void
    {
        $this->store()->save(['items.sku_prefix' => 'P-']);
        $this->assetItem(); // typed code, not numbered
        $this->store()->save(['items.sku_pad' => 3]);

        app(ItemCodeSequence::class)->next();

        $this->expectExceptionMessage(__('inventory::settings.locked_items_numbered'));
        $this->store()->save(['items.sku_pad' => 4]);
    }

    public function test_warehouses_cannot_be_turned_off_while_an_asset_is_stored_in_one(): void
    {
        $this->store()->save(['assets.store_in_warehouses' => true]);
        Asset::factory()->create(['location_type' => 'warehouse', 'location_id' => $this->warehouse()->id]);

        $this->expectExceptionMessage(__('inventory::settings.locked_assets_in_warehouses'));
        $this->store()->save(['assets.store_in_warehouses' => false]);
    }

    public function test_currency_locks_after_the_first_stock_movement(): void
    {
        $this->store()->save(['stock.currency' => 'EUR']);
        app(StockService::class)->receive($this->stockItem(), $this->in($this->warehouse()), 5, 2);

        $this->expectExceptionMessage(__('inventory::settings.locked_stock_moved'));
        $this->store()->save(['stock.currency' => 'USD']);
    }

    public function test_describe_reports_value_default_and_lock(): void
    {
        $this->store()->save(['assets.warranty_lead_days' => 12]);
        Asset::factory()->create();

        $rows = collect($this->store()->describe())->keyBy('key');

        $this->assertSame(12, $rows['assets.warranty_lead_days']['value']);
        $this->assertSame(30, $rows['assets.warranty_lead_days']['default']);
        $this->assertFalse($rows['assets.warranty_lead_days']['locked']);
        $this->assertTrue($rows['assets.code_prefix']['locked']);
        $this->assertSame(__('inventory::settings.locked_assets_exist'), $rows['assets.code_prefix']['locked_reason']);
        $this->assertSame(['auto', 'on_create', 'editable'], $rows['items.sku_mode']['options']);
    }
}
