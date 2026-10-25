<?php

namespace Tests\Feature\Inventory;

use Add\Inventory\Assets\Models\AssetAssignment;
use Add\Inventory\Assets\Models\MaintenanceRecord;
use Add\Inventory\Assets\Models\MaintenanceSchedule;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Stock\Models\StockBalance;
use Add\Inventory\Stock\Models\StockMovement;
use Add\Inventory\Support\Decimal;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\ResourceType;
use App\Domain\Retail\Models\SaleItem;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\InventoryDemoSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The inventory demo seeder goes through the package's own services, so a
 * rule change that breaks it shows here rather than on someone's machine.
 */
class InventoryDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_every_state_once(): void
    {
        $this->seed([RoleSeeder::class, PermissionSeeder::class, CurrencySeeder::class]);

        $this->seed(InventoryDemoSeeder::class);

        $assets = Item::query()->where('sku', 'like', 'DEMO-INV-%')->with('assets')->get()->flatMap->assets;
        $this->assertEqualsCanonicalizing(
            ['in_service', 'in_maintenance', 'retired', 'lost', 'disposed'],
            $assets->pluck('status')->map(fn ($s) => $s->value)->unique()->values()->all(),
        );

        $this->assertTrue(AssetAssignment::query()->whereNull('returned_at')->exists());
        $this->assertTrue(AssetAssignment::query()->whereNotNull('returned_at')->exists());
        $this->assertTrue(MaintenanceRecord::query()->whereNull('closed_at')->exists());
        $this->assertTrue(MaintenanceRecord::query()->whereNotNull('closed_at')->exists());
        $this->assertTrue(MaintenanceSchedule::query()->where('next_due_on', '<', now()->toDateString())->exists());

        $this->assertEqualsCanonicalizing(
            ['receipt', 'issue', 'transfer_out', 'transfer_in', 'adjustment'],
            StockMovement::query()->distinct()->pluck('type')->map(fn ($t) => $t->value)->all(),
        );
        // The history spreads over the past months.
        $this->assertTrue(StockMovement::query()->where('created_at', '<', now()->subMonths(2))->exists());
        // Every balance is its ledger.
        foreach (StockBalance::query()->get() as $balance) {
            $ledger = Decimal::add('0', ...StockMovement::query()->where('item_id', $balance->item_id)->where('location_type', $balance->location_type)->where('location_id', $balance->location_id)->pluck('quantity')->all());
            $this->assertSame(Decimal::trim($balance->quantity), Decimal::trim($ledger));
        }

        $this->assertSame(4, SaleItem::query()->count());
        $this->assertNotNull(ResourceType::query()->where('slug', 'printing')->value('inventory_item_id'));
        $this->assertTrue(User::query()->where('email', 'storekeeper@staff.demo')->sole()->can('inventory.stock.receive'));
        $this->assertFalse(User::query()->where('email', 'technician@staff.demo')->sole()->can('inventory.stock.receive'));

        $items = Item::query()->count();
        $this->seed(InventoryDemoSeeder::class);
        $this->assertSame($items, Item::query()->count());
    }
}
