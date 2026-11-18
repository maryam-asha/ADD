<?php

namespace Tests\Feature\Inventory;

use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Catalog\Models\Item;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Space;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 2026_10_24_300000 on rows shaped like production's: the table is rebuilt
 * (2026_10_24_300100 dropped it), filled, and the migration run again.
 */
class MoveResourcesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migrateRows(array $rows): void
    {
        (require database_path('migrations/2026_10_24_300100_drop_resources_table.php'))->down();
        foreach ($rows as $row) {
            DB::table('resources')->insert([
                'quantity' => 1,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
                ...$row,
            ]);
        }

        (require database_path('migrations/2026_10_24_300000_move_resources_to_inventory_and_amenities.php'))->up();
        Schema::dropIfExists('resources');
    }

    public function test_virtual_rows_become_amenities_and_equipment_becomes_assets(): void
    {
        $room = Space::factory()->create(['space_type' => 'room']);
        $hall = Space::factory()->create(['space_type' => 'event_hall']);

        $this->migrateRows([
            ['space_id' => $room->id, 'name' => 'High-Speed Wi-Fi', 'category' => 'other'],
            ['space_id' => $room->id, 'name' => 'Smart Lock Access', 'category' => 'other'],
            ['space_id' => $room->id, 'name' => 'Rooftop Terrace', 'category' => 'other'],
            ['space_id' => $room->id, 'name' => 'Old Fax', 'category' => 'other', 'status' => 'retired'],
            ['space_id' => $hall->id, 'name' => 'Stage & Podium', 'category' => 'other'],
            ['space_id' => $hall->id, 'name' => 'Audio System & Microphones', 'category' => 'mic', 'quantity' => 2],
            ['space_id' => $room->id, 'name' => 'Projector', 'category' => 'projector', 'status' => 'maintenance', 'status_reason' => 'Lamp'],
        ]);

        // Matched to the catalog, a new catalog entry, and allowed for the type.
        $this->assertDatabaseHas('space_amenities', ['space_id' => $room->id, 'amenity' => 'wifi']);
        $this->assertDatabaseHas('space_amenities', ['space_id' => $room->id, 'amenity' => 'rooftop_terrace']);
        $this->assertDatabaseHas('space_type_amenities', ['space_type' => 'room', 'amenity' => 'rooftop_terrace']);
        $this->assertDatabaseHas('space_amenities', ['space_id' => $hall->id, 'amenity' => 'stage']);
        // The lock is the space's own is_lockable; the retired row was never shown.
        $this->assertSame(2, DB::table('space_amenities')->where('space_id', $room->id)->count());

        $mics = Item::query()->get()->firstOrFail(fn (Item $item) => $item->name['en'] === 'Audio System & Microphones');
        $this->assertTrue($mics->category->show_to_members);
        $this->assertSame('volume-2', $mics->category->icon);
        $this->assertSame(2, Asset::query()->where('item_id', $mics->id)->where('location_type', 'space')->where('location_id', $hall->id)->count());

        $projector = Asset::query()->where('location_id', $room->id)->firstOrFail();
        $this->assertSame('in_maintenance', $projector->status->value);
        $this->assertSame('Lamp', $projector->openMaintenance->description);
        $this->assertSame('AST-'.str_pad((string) $projector->id, 6, '0', STR_PAD_LEFT), $projector->code);
        // One registration movement per asset: two microphones, one projector.
        $this->assertSame(3, DB::table('inv_asset_movements')->count());
    }

    public function test_equipment_with_no_space_goes_to_the_first_branch_to_be_placed(): void
    {
        $branch = Branch::factory()->create();

        $this->migrateRows([['space_id' => null, 'name' => 'Spare Screen', 'category' => 'screen']]);

        $asset = Asset::query()->firstOrFail();
        $this->assertSame(['branch', $branch->id], [$asset->location_type, $asset->location_id]);
        $this->assertStringContainsString('place it', $asset->notes);
    }
}
