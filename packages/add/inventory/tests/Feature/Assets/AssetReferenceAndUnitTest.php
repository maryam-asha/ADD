<?php

namespace Add\Inventory\Tests\Feature\Assets;

use Add\Inventory\Authorization\Abilities;
use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Catalog\Models\Location;
use Add\Inventory\Catalog\Models\Unit;
use Add\Inventory\Tests\Concerns\BuildsInventory;
use Add\Inventory\Tests\TestCase;

/**
 * An asset keeps where it came from — a reference such as the supplier's
 * invoice number, and that invoice attached — and an asset item needs no
 * unit: it is always counted one by one.
 */
class AssetReferenceAndUnitTest extends TestCase
{
    use BuildsInventory;

    private const ALL = [Abilities::ASSETS_VIEW, Abilities::ASSETS_MANAGE, Abilities::CATALOG_VIEW, Abilities::CATALOG_MANAGE];

    public function test_assets_are_registered_with_their_reference_and_attachment(): void
    {
        $this->actingAsWith(self::ALL);

        $assets = $this->postJson('/api/inventory/assets', [
            'item_id' => $this->assetItem()->id,
            'location' => $this->at(Location::factory()->create()),
            'count' => 2,
            'reference' => 'INV-2026-114',
            'attachments' => ['/storage/uploads/inventory/inv-114.pdf'],
        ])->assertCreated()->json('data');

        foreach ($assets as $asset) {
            $this->assertSame('INV-2026-114', $asset['reference']);
            $this->assertSame(['/storage/uploads/inventory/inv-114.pdf'], $asset['attachments']);
        }
    }

    public function test_an_assets_reference_and_attachment_can_be_corrected_and_must_be_a_file_url(): void
    {
        $this->actingAsWith(self::ALL);
        $id = $this->postJson('/api/inventory/assets', ['item_id' => $this->assetItem()->id, 'location' => $this->at(Location::factory()->create())])->json('data.0.id');

        $this->patchJson("/api/inventory/assets/{$id}", ['reference' => 'INV-9', 'attachments' => ['https://cdn.example.test/inv-9.pdf']])->assertOk();
        $this->getJson("/api/inventory/assets/{$id}")->assertOk()->assertJsonPath('data.reference', 'INV-9')->assertJsonPath('data.attachments', ['https://cdn.example.test/inv-9.pdf']);

        $this->patchJson("/api/inventory/assets/{$id}", ['attachments' => ['javascript:alert(1)']])->assertUnprocessable()->assertJsonValidationErrors('attachments.0');
        $this->patchJson("/api/inventory/assets/{$id}", ['reference' => str_repeat('x', 61)])->assertUnprocessable()->assertJsonValidationErrors('reference');
    }

    public function test_an_asset_item_needs_no_unit(): void
    {
        $this->actingAsWith(self::ALL);

        $this->postJson('/api/inventory/items', ['name' => ['ar' => 'لابتوب'], 'category_id' => Category::factory()->create()->id])
            ->assertCreated()->assertJsonPath('data.kind', 'asset')->assertJsonPath('data.unit', null);
    }

    public function test_a_stock_item_still_needs_its_unit(): void
    {
        $this->actingAsWith(self::ALL);

        $this->postJson('/api/inventory/items', ['name' => ['ar' => 'ورق'], 'category_id' => Category::factory()->stock()->create()->id])
            ->assertUnprocessable()->assertJsonValidationErrors('unit_id');
    }

    public function test_an_item_without_a_unit_cannot_move_to_a_stock_category_until_it_has_one(): void
    {
        $this->actingAsWith(self::ALL);
        $item = Item::factory()->create(['unit_id' => null]);
        $stock = Category::factory()->stock()->create();

        $this->patchJson("/api/inventory/items/{$item->id}", ['category_id' => $stock->id])
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::catalog.unit_required'));
        $this->patchJson("/api/inventory/items/{$item->id}", ['category_id' => $stock->id, 'unit_id' => Unit::factory()->create()->id])
            ->assertOk();
    }
}
