<?php

namespace Tests\Feature\Inventory;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ADD's side of the inventory warehouses: a warehouse sits in a branch or
 * in nothing, and the admin role holds the warehouse permissions.
 */
class WarehouseHostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
    }

    public function test_admin_holds_the_warehouse_permissions(): void
    {
        $this->getJson('/api/v1/inventory/warehouses')->assertOk();
    }

    public function test_roles_that_handle_stock_can_see_warehouses_after_deploy(): void
    {
        $keeper = Role::create(['name' => 'store_keeper_test', 'guard_name' => 'web']);
        $keeper->givePermissionTo('inventory.stock.receive');
        $viewer = Role::create(['name' => 'asset_viewer_test', 'guard_name' => 'web']);
        $viewer->givePermissionTo('inventory.assets.view');

        (require base_path('database/migrations/2026_11_05_200100_grant_warehouse_view_to_stock_roles.php'))->up();

        $this->assertTrue($keeper->refresh()->hasPermissionTo('inventory.warehouses.view'));
        $this->assertFalse($viewer->refresh()->hasPermissionTo('inventory.warehouses.view'));
    }

    public function test_a_warehouse_sits_in_a_branch_or_in_nothing(): void
    {
        $branch = Branch::factory()->create();
        $space = Space::factory()->create(['building_id' => Building::factory()->create(['branch_id' => $branch->id])->id]);

        $this->postJson('/api/v1/inventory/warehouses', ['name' => ['ar' => 'الكافيه'], 'place' => ['type' => 'branch', 'id' => $branch->id], 'is_primary' => true])
            ->assertCreated()->assertJsonPath('data.place.id', $branch->id);
        $this->postJson('/api/v1/inventory/warehouses', ['name' => ['ar' => 'خارجي']])
            ->assertCreated()->assertJsonPath('data.place', null);
        $this->postJson('/api/v1/inventory/warehouses', ['name' => ['ar' => 'غرفة'], 'place' => ['type' => 'space', 'id' => $space->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('place.type');
    }
}
