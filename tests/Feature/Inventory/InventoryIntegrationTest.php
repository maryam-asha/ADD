<?php

namespace Tests\Feature\Inventory;

use Add\Inventory\Assets\Services\AssetService;
use Add\Inventory\Authorization\Abilities;
use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Stock\Services\StockService;
use Add\Inventory\Support\Place;
use Add\Inventory\Warehouses\Models\Warehouse;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Floor;
use App\Domain\Foundation\Models\Space;
use App\Domain\Foundation\Models\Zone;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Notifications\GeneralNotification;
use App\Services\Permissions\PermissionSyncService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Feature\Hr\Concerns\HiresAddEmployees;
use Tests\TestCase;

/**
 * ADD's side of the inventory package: its places are ADD's branches,
 * buildings, floors, zones and spaces; its custodians ADD's employees,
 * members and companies; its abilities ADD permissions any role can hold.
 */
class InventoryIntegrationTest extends TestCase
{
    use HiresAddEmployees;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    private function storeKeeper(): User
    {
        Role::query()->firstOrCreate(['name' => 'store_keeper', 'guard_name' => 'web'])->givePermissionTo(Abilities::all());
        $user = User::factory()->create();
        $user->assignRole('store_keeper');
        Sanctum::actingAs($user, ['dashboard']);

        return $user;
    }

    private function space(): Space
    {
        $branch = Branch::factory()->create(['name' => ['en' => 'Aleppo', 'ar' => 'حلب']]);
        $building = Building::factory()->create(['branch_id' => $branch->id, 'name' => ['en' => 'Tower A', 'ar' => 'البرج أ']]);
        $floor = Floor::factory()->create(['building_id' => $building->id, 'label' => 'Floor 2']);
        $zone = Zone::factory()->create(['floor_id' => $floor->id, 'label' => 'East']);

        return Space::factory()->create(['building_id' => $building->id, 'zone_id' => $zone->id, 'name' => ['en' => 'Room 3', 'ar' => 'الغرفة 3']]);
    }

    public function test_sync_gives_every_inventory_ability_to_admin(): void
    {
        $result = app(PermissionSyncService::class)->sync();
        $admin = Role::findByName('admin', 'web');

        foreach (Abilities::all() as $ability) {
            $this->assertTrue($admin->hasPermissionTo($ability), "admin lacks {$ability}");
            $this->assertNotContains($ability, $result['stale']);
        }
    }

    public function test_a_custom_role_registers_an_asset_in_an_add_space(): void
    {
        $this->storeKeeper();
        $space = $this->space();
        $item = Item::factory()->create();

        $this->postJson('/api/v1/inventory/assets', [
            'item_id' => $item->id,
            'location' => ['type' => 'space', 'id' => $space->id],
            'purchase_cost' => 300,
            'currency' => 'USD',
        ])
            ->assertCreated()
            // In the request's locale (ADD's default, Arabic, here).
            ->assertJsonPath('data.0.location.label', 'الغرفة 3')
            ->assertJsonPath('data.0.location.path', ['حلب', 'البرج أ', 'Floor 2', 'East']);

        $this->postJson('/api/v1/inventory/assets', ['item_id' => $item->id, 'location' => ['type' => 'space', 'id' => 999999]])
            ->assertUnprocessable()->assertJsonValidationErrors('location');
        $this->postJson('/api/v1/inventory/assets', ['item_id' => $item->id, 'location' => ['type' => 'space', 'id' => $space->id], 'purchase_cost' => 1, 'currency' => 'XYZ'])
            ->assertUnprocessable()->assertJsonValidationErrors('currency');
        // The package's own location tree is not mounted: ADD has places.
        $this->getJson('/api/v1/inventory/locations')->assertNotFound();
    }

    public function test_stock_in_a_branch_counts_every_warehouse_of_it(): void
    {
        $this->storeKeeper();
        $branchId = $this->space()->building->branch_id;
        $paper = Item::factory()->create(['category_id' => Category::factory()->stock()->create()->id]);
        $main = Warehouse::factory()->create(['place_type' => 'branch', 'place_id' => $branchId, 'is_primary' => true]);
        $cafe = Warehouse::factory()->create(['place_type' => 'branch', 'place_id' => $branchId]);
        $elsewhere = Warehouse::factory()->create(['place_type' => 'branch', 'place_id' => Branch::factory()->create()->id]);
        $stock = app(StockService::class);
        $stock->receive($paper, $main->asPlace(), 20, 3);
        $stock->receive($paper, $cafe->asPlace(), 5, 3);
        $stock->receive($paper, $elsewhere->asPlace(), 7, 3);

        $this->getJson("/api/v1/inventory/stock/balances?place[type]=branch&place[id]={$branchId}")
            ->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/inventory/reports/stock-valuation?place[type]=branch&place[id]={$branchId}")
            ->assertOk()->assertJsonPath('data.total', '75');
    }

    public function test_assets_are_handed_to_employees_members_and_companies(): void
    {
        $this->storeKeeper();
        $space = $this->space();
        $service = app(AssetService::class);
        [$laptop, $key, $desk] = [
            $service->register(Item::factory()->create(), new Place('space', $space->id))->first(),
            $service->register(Item::factory()->create(), new Place('space', $space->id))->first(),
            $service->register(Item::factory()->create(), new Place('space', $space->id))->first(),
        ];
        $employee = $this->hireFor(User::factory()->create(['name' => 'Lina']));
        $member = User::factory()->create(['name' => 'Omar']);
        $company = Company::factory()->create(['legal_name' => 'Acme LLC']);

        $this->postJson("/api/v1/inventory/assets/{$laptop->id}/assign", ['custodian' => ['type' => 'hr_employee', 'id' => $employee->id]])
            ->assertCreated()->assertJsonPath('data.custodian.label', "Lina ({$employee->employee_number})");
        $this->postJson("/api/v1/inventory/assets/{$key->id}/assign", ['custodian' => ['type' => 'member', 'id' => $member->id]])
            ->assertCreated()->assertJsonPath('data.custodian.label', 'Omar');
        $this->postJson("/api/v1/inventory/assets/{$desk->id}/assign", ['custodian' => ['type' => 'company', 'id' => $company->id]])
            ->assertCreated()->assertJsonPath('data.custodian.label', 'Acme LLC');

        $this->getJson("/api/v1/inventory/custody?custodian[type]=hr_employee&custodian[id]={$employee->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.asset.id', $laptop->id);
    }

    public function test_the_member_app_and_staff_without_the_permission_are_kept_out(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['member-app']);
        $this->getJson('/api/v1/inventory/assets')->assertForbidden();

        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['dashboard']);
        $this->getJson('/api/v1/inventory/assets')->assertForbidden();
    }

    public function test_maintenance_due_reaches_holders_of_the_maintenance_permission(): void
    {
        Notification::fake();
        Role::create(['name' => 'technician', 'guard_name' => 'web'])->givePermissionTo(Abilities::ASSETS_MAINTENANCE);
        $technician = User::factory()->create();
        $technician->assignRole('technician');
        $bystander = User::factory()->create();
        $bystander->assignRole('operations');

        $asset = app(AssetService::class)->register(Item::factory()->create(), new Place('space', $this->space()->id))->first();
        $asset->maintenanceSchedules()->create(['title' => 'Filter', 'interval_months' => 3, 'next_due_on' => now()->addDay()->toDateString()]);

        $this->artisan('inventory:run-daily')->assertSuccessful();

        Notification::assertSentTo($technician, GeneralNotification::class);
        Notification::assertNotSentTo($bystander, GeneralNotification::class);
    }
}
