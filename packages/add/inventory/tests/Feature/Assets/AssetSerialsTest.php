<?php

namespace Add\Inventory\Tests\Feature\Assets;

use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Authorization\Abilities;
use Add\Inventory\Catalog\Models\Location;
use Add\Inventory\Tests\Concerns\BuildsInventory;
use Add\Inventory\Tests\TestCase;

/**
 * Several units of one item registered together each take their own
 * serial, given one per unit; a serial is not given twice to the same item.
 */
class AssetSerialsTest extends TestCase
{
    use BuildsInventory;

    private const ALL = [Abilities::ASSETS_VIEW, Abilities::ASSETS_MANAGE];

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function batch(array $lines): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/inventory/assets/batch', [
            'location' => $this->at(Location::factory()->create()),
            'lines' => $lines,
        ]);
    }

    public function test_a_line_of_several_takes_a_serial_per_unit(): void
    {
        $this->actingAsWith(self::ALL);
        $chair = $this->assetItem();

        $this->batch([['item_id' => $chair->id, 'count' => 3, 'serial_numbers' => ['A1', null, ' A3 ']]])->assertCreated();

        $this->assertSame(['A1', null, 'A3'], Asset::query()->orderBy('id')->pluck('serial_number')->all());
    }

    public function test_the_single_endpoint_takes_serials_too(): void
    {
        $this->actingAsWith(self::ALL);

        $this->postJson('/api/inventory/assets', [
            'item_id' => $this->assetItem()->id,
            'location' => $this->at(Location::factory()->create()),
            'count' => 2,
            'serial_numbers' => ['S1', 'S2'],
        ])->assertCreated();

        $this->assertSame(['S1', 'S2'], Asset::query()->orderBy('id')->pluck('serial_number')->all());
    }

    public function test_more_serials_than_units_is_refused(): void
    {
        $this->actingAsWith(self::ALL);

        $this->batch([['item_id' => $this->assetItem()->id, 'count' => 2, 'serial_numbers' => ['A', 'B', 'C']]])
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.serial_numbers');
        $this->assertSame(0, Asset::query()->count());
    }

    public function test_a_serial_repeated_in_a_line_is_refused(): void
    {
        $this->actingAsWith(self::ALL);

        $this->batch([['item_id' => $this->assetItem()->id, 'count' => 2, 'serial_numbers' => ['X', 'x ']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.serial_numbers.1')
            ->assertJsonMissingValidationErrors('lines.0.serial_numbers.0');
        $this->assertSame(0, Asset::query()->count());
    }

    public function test_a_serial_repeated_across_lines_of_the_same_item_is_refused(): void
    {
        $this->actingAsWith(self::ALL);
        $chair = $this->assetItem();

        $this->batch([
            ['item_id' => $chair->id, 'serial_numbers' => ['X']],
            ['item_id' => $chair->id, 'serial_number' => 'x'],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines.1.serial_number');
    }

    public function test_different_items_may_share_a_serial(): void
    {
        $this->actingAsWith(self::ALL);

        $this->batch([
            ['item_id' => $this->assetItem()->id, 'serial_numbers' => ['SAME']],
            ['item_id' => $this->assetItem()->id, 'serial_numbers' => ['SAME']],
        ])->assertCreated();
    }

    public function test_a_serial_an_asset_of_the_same_item_already_has_is_refused(): void
    {
        $this->actingAsWith(self::ALL);
        $existing = Asset::factory()->create(['serial_number' => 'OLD']);

        $this->batch([['item_id' => $existing->item_id, 'count' => 2, 'serial_numbers' => [null, ' old']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.serial_numbers.1' => __('inventory::assets.serial_taken')]);
    }

    public function test_sending_both_serial_fields_is_refused(): void
    {
        $this->actingAsWith(self::ALL);

        $this->batch([['item_id' => $this->assetItem()->id, 'serial_number' => 'A', 'serial_numbers' => ['B']]])
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.serial_numbers');
    }

    public function test_the_single_endpoint_refuses_one_serial_for_several(): void
    {
        $this->actingAsWith(self::ALL);

        $this->postJson('/api/inventory/assets', [
            'item_id' => $this->assetItem()->id,
            'location' => $this->at(Location::factory()->create()),
            'count' => 3,
            'serial_number' => 'S',
        ])->assertUnprocessable()->assertJsonValidationErrors(['serial_number' => __('inventory::assets.serial_for_one')]);
        $this->assertSame(0, Asset::query()->count());
    }

    public function test_editing_to_a_serial_taken_by_the_same_item_is_refused(): void
    {
        $this->actingAsWith(self::ALL);
        $a = Asset::factory()->create(['serial_number' => 'A-1']);
        $b = Asset::factory()->create(['item_id' => $a->item_id, 'serial_number' => 'B-1']);

        $this->patchJson("/api/inventory/assets/{$b->id}", ['serial_number' => 'a-1'])
            ->assertUnprocessable()->assertJsonValidationErrors('serial_number');
    }

    /**
     * Before serials were checked, one serial could land on several units
     * of an item. Editing such an asset without touching its serial must
     * still save; only a changed serial is checked.
     */
    public function test_editing_an_asset_whose_serial_was_already_shared_saves_when_the_serial_is_unchanged(): void
    {
        $this->actingAsWith(self::ALL);
        $a = Asset::factory()->create(['serial_number' => 'S']);
        Asset::factory()->create(['item_id' => $a->item_id, 'serial_number' => 'S']);

        $this->patchJson("/api/inventory/assets/{$a->id}", ['serial_number' => 'S', 'notes' => 'checked'])->assertOk();
        $this->patchJson("/api/inventory/assets/{$a->id}", ['serial_number' => ' s '])->assertOk();
    }

    public function test_editing_keeps_its_own_serial(): void
    {
        $this->actingAsWith(self::ALL);
        $a = Asset::factory()->create(['serial_number' => 'A-1']);

        $this->patchJson("/api/inventory/assets/{$a->id}", ['serial_number' => 'A-1', 'notes' => 'x'])->assertOk();
    }
}
