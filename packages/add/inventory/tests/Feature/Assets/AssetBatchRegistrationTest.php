<?php

namespace Add\Inventory\Tests\Feature\Assets;

use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Authorization\Abilities;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Catalog\Models\Location;
use Add\Inventory\Tests\Concerns\BuildsInventory;
use Add\Inventory\Tests\TestCase;

/**
 * POST assets/batch — what a room holds, entered once: the place and the
 * purchase's shared fields, then a line per asset item. All or none.
 */
class AssetBatchRegistrationTest extends TestCase
{
    use BuildsInventory;

    private const ALL = [Abilities::ASSETS_VIEW, Abilities::ASSETS_MANAGE];

    public function test_several_items_go_to_one_place_with_the_shared_fields_on_each(): void
    {
        $this->actingAsWith(self::ALL);
        $room = Location::factory()->create();
        [$chair, $desk] = [$this->assetItem(), $this->assetItem()];

        $assets = $this->postJson('/api/inventory/assets/batch', [
            'location' => $this->at($room),
            'supplier' => 'مفروشات حلب',
            'reference' => 'INV-77',
            'currency' => 'USD',
            'lines' => [
                ['item_id' => $chair->id, 'count' => 4, 'purchase_cost' => 25],
                ['item_id' => $desk->id, 'serial_number' => 'D-1', 'purchase_cost' => 120, 'warranty_until' => '2027-11-01'],
            ],
        ])->assertCreated()->json('data');

        $this->assertCount(5, $assets);
        $this->assertSame(4, Asset::query()->where('item_id', $chair->id)->count());
        $this->assertTrue(Asset::query()->where('location_id', '!=', $room->id)->doesntExist());
        $this->assertSame(5, Asset::query()->where('reference', 'INV-77')->where('supplier', 'مفروشات حلب')->where('currency', 'USD')->count());

        $desk = Asset::query()->where('item_id', $desk->id)->sole();
        $this->assertSame('D-1', $desk->serial_number);
        $this->assertSame('2027-11-01', $desk->warranty_until->format('Y-m-d'));
    }

    public function test_a_bad_line_is_named_and_nothing_is_registered(): void
    {
        $this->actingAsWith(self::ALL);
        $inactive = $this->assetItem();
        $inactive->update(['is_active' => false]);

        $this->postJson('/api/inventory/assets/batch', [
            'location' => $this->at(Location::factory()->create()),
            'lines' => [
                ['item_id' => $this->assetItem()->id],
                ['item_id' => $this->stockItem()->id],
                ['item_id' => $inactive->id],
                ['item_id' => $this->assetItem()->id, 'count' => 2, 'serial_number' => 'S-1'],
                ['item_id' => $this->assetItem()->id, 'purchase_cost' => 10, 'salvage_value' => 20],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'lines.1.item_id', 'lines.2.item_id', 'lines.3.serial_number', 'lines.4.salvage_value', 'currency',
        ])->assertJsonMissingValidationErrors('lines.0.item_id');

        $this->assertSame(0, Asset::query()->count());
    }

    public function test_it_needs_a_place_and_a_line(): void
    {
        $this->actingAsWith(self::ALL);

        $this->postJson('/api/inventory/assets/batch', ['lines' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['location', 'lines']);
    }

    public function test_it_needs_the_manage_ability(): void
    {
        $this->actingAsWith([Abilities::ASSETS_VIEW]);

        $this->postJson('/api/inventory/assets/batch', [
            'location' => $this->at(Location::factory()->create()),
            'lines' => [['item_id' => $this->assetItem()->id]],
        ])->assertForbidden();
        $this->assertSame(0, Asset::query()->count());
        $this->assertSame(1, Item::query()->count());
    }
}
