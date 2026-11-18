<?php

namespace Tests\Feature\Inventory;

use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Support\Decimal;
use Add\Inventory\Warehouses\Models\Warehouse;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Stock counted on ADD's places before warehouses existed moves into
 * warehouses with its whole history: only where each row points changes.
 * The rows are written the old way, straight into the tables, as they sit
 * in a database migrated before 2026-11-05.
 */
class MoveStockIntoWarehousesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_11_05_200000_move_stock_into_warehouses.php';

    public function test_existing_stock_moves_into_warehouses_with_its_history(): void
    {
        $branch = Branch::factory()->create(['name' => ['en' => 'Aziziyah', 'ar' => 'العزيزية']]);
        $empty = Branch::factory()->create(['name' => ['en' => 'Shahba', 'ar' => 'الشهباء']]);
        $space = Space::factory()->create(['building_id' => Building::factory()->create(['branch_id' => $branch->id])->id]);
        $paper = Item::factory()->stock()->create();
        $coffee = Item::factory()->stock()->create();
        $actor = User::factory()->create();

        $this->ledger($paper, 'branch', $branch->id, ['10', '-3'], $actor->id);
        $this->ledger($coffee, 'space', $space->id, ['4'], $actor->id);
        $this->ledger($coffee, 'space', 99999, ['2', '-2'], null);
        $sale = DB::table('item_sales')->insertGetId([
            'user_id' => $actor->id, 'location_type' => 'branch', 'location_id' => $branch->id,
            'total_points' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $before = DB::table('inv_stock_movements')->orderBy('id')->get(['id', 'quantity', 'unit_cost', 'created_at', 'actor_id', 'reason', 'source_type', 'source_id', 'note']);

        (require base_path(self::MIGRATION))->up();

        $main = Warehouse::query()->where('place_type', 'branch')->where('place_id', $branch->id)->where('is_primary', true)->sole();
        $this->assertTrue($main->is_primary);
        $this->assertTrue($main->allows_sales);
        $this->assertSame('Main warehouse – Aziziyah', $main->name['en']);
        $this->assertSame('المستودع الرئيسي – العزيزية', $main->name['ar']);
        $this->assertTrue(Warehouse::query()->where('place_type', 'branch')->where('place_id', $empty->id)->where('is_primary', true)->exists());

        $fromSpace = Warehouse::query()->whereKeyNot($main->id)->where('place_id', $branch->id)->sole();
        $this->assertFalse($fromSpace->is_primary);
        $this->assertFalse($fromSpace->allows_sales);
        $this->assertTrue($fromSpace->is_active);
        $this->assertSame($space->displayTitle(), $fromSpace->name['en']);

        $unknown = Warehouse::query()->whereNull('place_type')->sole();
        $this->assertSame('Unknown place #space:99999', $unknown->name['en']);
        $this->assertFalse($unknown->is_active);

        $this->assertSame(0, DB::table('inv_stock_movements')->where('location_type', '!=', 'warehouse')->count());
        $this->assertSame(0, DB::table('inv_stock_balances')->where('location_type', '!=', 'warehouse')->count());
        $this->assertSame('7', Decimal::trim(DB::table('inv_stock_balances')->where('location_id', $main->id)->value('quantity')));
        $this->assertSame($main->id, (int) DB::table('item_sales')->where('id', $sale)->value('location_id'));
        $this->assertSame('warehouse', DB::table('item_sales')->where('id', $sale)->value('location_type'));

        foreach (DB::table('inv_stock_balances')->get() as $balance) {
            $ledger = DB::table('inv_stock_movements')->where('item_id', $balance->item_id)->where('location_type', $balance->location_type)->where('location_id', $balance->location_id)->pluck('quantity')->all();
            $this->assertSame(Decimal::trim($balance->quantity), Decimal::trim(Decimal::add('0', ...$ledger)));
        }

        $this->assertEquals($before, DB::table('inv_stock_movements')->orderBy('id')->get(['id', 'quantity', 'unit_cost', 'created_at', 'actor_id', 'reason', 'source_type', 'source_id', 'note']));
    }

    /**
     * Ledger rows and their balance at a place, the pre-warehouse way.
     *
     * @param  list<string>  $quantities
     */
    private function ledger(Item $item, string $type, int $id, array $quantities, ?int $actor): void
    {
        $after = '0';

        foreach ($quantities as $quantity) {
            $after = Decimal::add($after, $quantity);
            DB::table('inv_stock_movements')->insert([
                'item_id' => $item->id, 'location_type' => $type, 'location_id' => $id,
                'type' => str_starts_with($quantity, '-') ? 'issue' : 'receipt', 'quantity' => $quantity,
                'unit_cost' => '1', 'quantity_after' => $after, 'average_cost_after' => '1',
                'reason' => str_starts_with($quantity, '-') ? 'internal_use' : null,
                'note' => 'before warehouses', 'actor_id' => $actor, 'created_at' => now()->subMonth(),
            ]);
        }

        DB::table('inv_stock_balances')->insert([
            'item_id' => $item->id, 'location_type' => $type, 'location_id' => $id,
            'quantity' => $after, 'average_cost' => '1', 'updated_at' => now(),
        ]);
    }
}
