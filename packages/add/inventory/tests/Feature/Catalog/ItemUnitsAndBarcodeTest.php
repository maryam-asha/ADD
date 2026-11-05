<?php

namespace Add\Inventory\Tests\Feature\Catalog;

use Add\Inventory\Authorization\Abilities;
use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Catalog\Models\Unit;
use Add\Inventory\Stock\Services\StockService;
use Add\Inventory\Tests\Concerns\BuildsInventory;
use Add\Inventory\Tests\TestCase;

class ItemUnitsAndBarcodeTest extends TestCase
{
    use BuildsInventory;

    private const ALL = [Abilities::CATALOG_VIEW, Abilities::CATALOG_MANAGE];

    public function test_store_with_units_barcode_and_image(): void
    {
        $this->actingAsWith(self::ALL);
        $piece = Unit::factory()->create();
        $box = Unit::factory()->create(['name' => ['ar' => 'علبة', 'en' => 'box']]);

        $this->postJson('/api/inventory/items', [
            'name' => ['ar' => 'مياه معدنية'],
            'category_id' => Category::factory()->stock()->create()->id,
            'unit_id' => $piece->id,
            'barcode' => '2000000000015',
            'image_url' => 'https://cdn.example.test/uploads/water.png',
            'units' => [['unit_id' => $box->id, 'factor' => 6]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.barcode', '2000000000015')
            ->assertJsonPath('data.image_url', 'https://cdn.example.test/uploads/water.png')
            ->assertJsonPath('data.units.0.unit.id', $box->id)
            ->assertJsonPath('data.units.0.factor', '6');
    }

    public function test_main_unit_cannot_be_another_unit(): void
    {
        $this->actingAsWith(self::ALL);
        $piece = Unit::factory()->create();

        $this->postJson('/api/inventory/items', [
            'name' => ['ar' => 'مياه'],
            'category_id' => Category::factory()->stock()->create()->id,
            'unit_id' => $piece->id,
            'units' => [['unit_id' => $piece->id, 'factor' => 6]],
        ])->assertUnprocessable()->assertJsonValidationErrors('units.0.unit_id');
    }

    public function test_factor_must_be_positive_and_units_distinct(): void
    {
        $this->actingAsWith(self::ALL);
        $box = Unit::factory()->create();
        $payload = ['name' => ['ar' => 'مياه'], 'category_id' => Category::factory()->stock()->create()->id, 'unit_id' => Unit::factory()->create()->id];

        $this->postJson('/api/inventory/items', [...$payload, 'units' => [['unit_id' => $box->id, 'factor' => 0]]])
            ->assertUnprocessable()->assertJsonValidationErrors('units.0.factor');
        $this->postJson('/api/inventory/items', [...$payload, 'units' => [['unit_id' => $box->id, 'factor' => 6], ['unit_id' => $box->id, 'factor' => 12]]])
            ->assertUnprocessable()->assertJsonValidationErrors('units.1.unit_id');
    }

    public function test_duplicate_barcode_is_refused(): void
    {
        $this->actingAsWith(self::ALL);
        Item::factory()->stock()->create(['barcode' => '2000000000015']);

        $this->postJson('/api/inventory/items', [
            'name' => ['ar' => 'مياه'],
            'category_id' => Category::factory()->stock()->create()->id,
            'unit_id' => Unit::factory()->create()->id,
            'barcode' => '2000000000015',
        ])->assertUnprocessable()->assertJsonValidationErrors('barcode');
    }

    public function test_lookup_by_barcode(): void
    {
        $this->actingAsWith(self::ALL);
        $item = Item::factory()->stock()->create(['barcode' => '6291041500213']);

        $this->getJson('/api/inventory/items/barcode/6291041500213')->assertOk()->assertJsonPath('data.id', $item->id);
        $this->getJson('/api/inventory/items/barcode/0000000000000')->assertNotFound();
    }

    public function test_generate_returns_an_unused_barcode(): void
    {
        $this->actingAsWith(self::ALL);

        $code = $this->postJson('/api/inventory/items/barcodes')->assertCreated()->json('data.barcode');

        $this->assertMatchesRegularExpression('/^2\d{12}$/', $code);
        $this->assertFalse(Item::query()->where('barcode', $code)->exists());
    }

    public function test_unit_used_by_a_movement_is_locked(): void
    {
        $this->actingAsWith(self::ALL);
        $item = $this->stockItem();
        $box = Unit::factory()->create();
        $item->otherUnits()->create(['unit_id' => $box->id, 'factor' => 6]);
        app(StockService::class)->receive($item, $this->in($this->warehouse()), 12, meta: ['entered_unit_id' => $box->id, 'entered_quantity' => 2]);

        $this->patchJson("/api/inventory/items/{$item->id}", ['units' => []])
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::catalog.unit_in_use_by_movements'));
        $this->patchJson("/api/inventory/items/{$item->id}", ['units' => [['unit_id' => $box->id, 'factor' => 12]]])
            ->assertUnprocessable();
        $this->patchJson("/api/inventory/items/{$item->id}", ['units' => [['unit_id' => $box->id, 'factor' => 6]]])
            ->assertOk();
    }

    public function test_search_matches_barcode(): void
    {
        $this->actingAsWith(self::ALL);
        $item = Item::factory()->stock()->create(['barcode' => '6291041500213']);
        Item::factory()->stock()->create();

        $this->getJson('/api/inventory/items?search=62910415')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $item->id);
    }

    public function test_factor_for_a_unit(): void
    {
        $item = $this->stockItem();
        $box = Unit::factory()->create();
        $item->otherUnits()->create(['unit_id' => $box->id, 'factor' => 6]);

        $this->assertSame('1', $item->factorFor($item->unit_id));
        $this->assertSame('6', $item->refresh()->factorFor($box->id));

        $this->expectExceptionMessage(__('inventory::catalog.unit_not_of_item'));
        $item->factorFor(Unit::factory()->create()->id);
    }
}
