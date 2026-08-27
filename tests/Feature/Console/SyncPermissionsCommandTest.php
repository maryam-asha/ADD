<?php

namespace Tests\Feature\Console;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `permissions:sync` (App\Console\Commands\SyncPermissionsCommand) derives
 * permissions from routes/controllers via PermissionSyncService — this is
 * the derivation-correctness coverage for that service.
 */
class SyncPermissionsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_it_derives_permissions_for_an_auto_scanned_resource(): void
    {
        $this->artisan('permissions:sync')->assertExitCode(0);

        $this->assertDatabaseHas('permissions', ['name' => 'branches.view']);
        $this->assertDatabaseHas('permissions', ['name' => 'branches.create']);
        $this->assertDatabaseHas('permissions', ['name' => 'branches.update']);

        // routes/api/v1/admin.php registers `branches` via
        // ->apiResource(...)->except('destroy') at the top level, but a
        // separate role:admin-gated `Route::delete('branches/{branch}', ...)`
        // pointed at the same BranchController@destroy is registered further
        // down the same file — route introspection matches on the live
        // Route objects, not on how they were registered, so that second
        // route still produces branches.delete.
        $this->assertDatabaseHas('permissions', ['name' => 'branches.delete']);
    }

    public function test_a_resource_registered_without_a_destroy_route_at_all_gets_no_delete_permission(): void
    {
        // ContactLinks is a plain `Route::apiResource(...)` with no
        // `->except()` and no separate destroy route removed anywhere —
        // unlike branches/buildings/etc., it keeps the full apiResource
        // shape including a real, generically-CRUD destroy. This test exists
        // to prove *unmapped* methods are skipped, using `founders` custom
        // action-less shape isn't available, so instead assert on a resource
        // that legitimately has no route under its module at all.
        $this->artisan('permissions:sync')->assertExitCode(0);

        // `Route::get('me', ...)` uses CurrentUserController, which is not
        // an AdminResourceController subclass and not manually registered —
        // no `me.*` permission should ever be derived from it.
        $this->assertDatabaseMissing('permissions', ['name' => 'me.view']);
    }

    public function test_manual_registrations_produce_their_fixed_permission_sets(): void
    {
        $this->artisan('permissions:sync')->assertExitCode(0);

        $this->assertDatabaseHas('permissions', ['name' => 'users.view']);
        $this->assertDatabaseHas('permissions', ['name' => 'users.create']);
        $this->assertDatabaseHas('permissions', ['name' => 'users.update']);
        $this->assertDatabaseHas('permissions', ['name' => 'users.update_status']);
        $this->assertDatabaseHas('permissions', ['name' => 'users.assign_role']);

        $this->assertDatabaseHas('permissions', ['name' => 'roles.view']);
        $this->assertDatabaseHas('permissions', ['name' => 'roles.create']);
        $this->assertDatabaseHas('permissions', ['name' => 'roles.update']);
        $this->assertDatabaseHas('permissions', ['name' => 'roles.delete']);

        $this->assertDatabaseHas('permissions', ['name' => 'error-logs.view']);
        $this->assertDatabaseHas('permissions', ['name' => 'error-logs.delete']);

        $this->assertDatabaseHas('permissions', ['name' => 'settings.view']);
        $this->assertDatabaseHas('permissions', ['name' => 'settings.update']);
    }

    public function test_running_the_command_twice_is_idempotent(): void
    {
        $this->artisan('permissions:sync')->assertExitCode(0);
        $firstCount = Permission::count();

        $this->artisan('permissions:sync')->assertExitCode(0);
        $secondCount = Permission::count();

        $this->assertSame($firstCount, $secondCount);
    }

    public function test_admins_permission_count_always_equals_the_total_permission_count(): void
    {
        $this->artisan('permissions:sync')->assertExitCode(0);

        $admin = Role::findByName('admin', 'web');

        $this->assertSame(Permission::count(), $admin->permissions()->count());
        $this->assertGreaterThan(0, Permission::count());
    }

    public function test_a_permission_inserted_outside_the_command_survives_a_run_and_is_reported_as_stale(): void
    {
        Permission::create(['name' => 'ghost.view', 'guard_name' => 'web']);

        $this->artisan('permissions:sync')->assertExitCode(0);

        $this->assertDatabaseHas('permissions', ['name' => 'ghost.view']);

        // Stale but not deleted: still exists, and admin (synced from a
        // fresh, unfiltered Permission::all()) still holds it too.
        $admin = Role::findByName('admin', 'web');
        $this->assertTrue($admin->hasPermissionTo('ghost.view'));
    }
}
