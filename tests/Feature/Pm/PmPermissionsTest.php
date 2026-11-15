<?php

namespace Tests\Feature\Pm;

use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The project-management package's abilities reach the roles screen through
 * a registered permission source, and admin holds them.
 */
class PmPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pm_abilities_are_permissions_admin_holds(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $names = Permission::where('name', 'like', 'pm.%')->pluck('name')->sort()->values()->all();

        $this->assertSame(['pm.projects.manage', 'pm.projects.view'], $names);

        $admin = Role::findByName('admin', 'web');
        $this->assertTrue($admin->hasPermissionTo('pm.projects.view'));
        $this->assertTrue($admin->hasPermissionTo('pm.projects.manage'));
    }
}
