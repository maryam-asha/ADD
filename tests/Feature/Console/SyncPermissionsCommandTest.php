<?php

namespace Tests\Feature\Console;

use Add\Hr\Authorization\Abilities as HrAbilities;
use Add\Inventory\Authorization\Abilities as InventoryAbilities;
use App\Domain\Identity\Models\User;
use App\Services\Permissions\PermissionSyncService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `permissions:sync` (App\Console\Commands\SyncPermissionsCommand) derives
 * permissions from the admin routes' `permission:` middleware, plus the
 * tagged permission sources, via PermissionSyncService — this is the
 * derivation-correctness coverage for that service
 * (docs/decisions/rbac-permission-pilot.md, 2026-10-27).
 */
class SyncPermissionsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_it_derives_permissions_from_route_middleware(): void
    {
        $this->artisan('permissions:sync')->assertExitCode(0);

        foreach ([
            'branches.view', 'branches.create', 'branches.update', 'branches.delete',
            'amenities.delete', 'reception.check_in', 'events.manage_attendees', 'kiosks.pair',
            'users.view', 'users.assign_role', 'roles.update', 'error-logs.delete', 'settings.update',
        ] as $name) {
            $this->assertDatabaseHas('permissions', ['name' => $name]);
        }
    }

    public function test_every_side_of_an_any_of_middleware_becomes_a_permission(): void
    {
        // GET users is `permission:users.view|members.view|reception.view`.
        $names = app(PermissionSyncService::class)->deriveNames();

        $this->assertContains('users.view', $names);
        $this->assertContains('members.view', $names);
        $this->assertContains('reception.view', $names);
    }

    public function test_a_route_with_no_permission_middleware_yields_no_permission(): void
    {
        $this->artisan('permissions:sync')->assertExitCode(0);

        // `me` and `media/upload` sit behind `staff`, not a permission.
        $this->assertDatabaseMissing('permissions', ['name' => 'me.view']);
        $this->assertDatabaseMissing('permissions', ['name' => 'media.create']);
    }

    public function test_permission_sources_are_included(): void
    {
        $names = app(PermissionSyncService::class)->deriveNames();

        foreach ([...HrAbilities::all(), ...InventoryAbilities::all()] as $ability) {
            $this->assertContains($ability, $names);
        }
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

    public function test_a_stale_permission_is_reported_and_kept_without_prune(): void
    {
        Permission::create(['name' => 'ghost.view', 'guard_name' => 'web']);

        $this->artisan('permissions:sync')
            ->expectsOutputToContain('ghost.view')
            ->assertExitCode(0);

        $this->assertDatabaseHas('permissions', ['name' => 'ghost.view']);

        // Stale but not deleted: still exists, and admin (synced from a
        // fresh, unfiltered Permission::all()) still holds it too.
        $admin = Role::findByName('admin', 'web');
        $this->assertTrue($admin->hasPermissionTo('ghost.view'));
    }

    public function test_prune_deletes_stale_permissions_and_every_grant_of_them(): void
    {
        $ghost = Permission::create(['name' => 'ghost.view', 'guard_name' => 'web']);
        Role::findOrCreate('front-desk', 'web')->givePermissionTo(['ghost.view', 'amenities.view']);
        User::factory()->create()->givePermissionTo('ghost.view');

        $this->artisan('permissions:sync', ['--prune' => true])
            ->expectsOutputToContain('ghost.view')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('permissions', ['name' => 'ghost.view']);
        $this->assertDatabaseMissing('role_has_permissions', ['permission_id' => $ghost->id]);
        $this->assertDatabaseMissing('model_has_permissions', ['permission_id' => $ghost->id]);
        $this->assertSame(['amenities.view'], Role::findByName('front-desk', 'web')->permissions()->pluck('name')->all());
        $this->assertSame(Permission::count(), Role::findByName('admin', 'web')->permissions()->count());
    }

    public function test_prune_leaves_every_derived_permission_alone(): void
    {
        $before = Permission::count();

        $this->artisan('permissions:sync', ['--prune' => true])->assertExitCode(0);

        $this->assertSame($before, Permission::count());
        $this->assertEqualsCanonicalizing(
            app(PermissionSyncService::class)->deriveNames(),
            Permission::query()->pluck('name')->all()
        );
    }
}
