<?php

namespace Tests\Feature\Hr;

use Add\Hr\Authorization\Abilities;
use App\Services\Permissions\PermissionSyncService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The HR package's abilities reach the roles screen through a registered
 * permission source, so `permissions:sync` creates them, gives them to admin,
 * and does not report them as stale.
 */
class HrPermissionsSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_creates_hr_abilities_and_gives_them_to_admin_without_marking_them_stale(): void
    {
        $this->seed(RoleSeeder::class);

        $result = app(PermissionSyncService::class)->sync();

        $admin = Role::findByName('admin', 'web');

        foreach (Abilities::all() as $ability) {
            $this->assertDatabaseHas('permissions', ['name' => $ability, 'guard_name' => 'web']);
            $this->assertTrue($admin->hasPermissionTo($ability), "admin lacks {$ability}");
            $this->assertNotContains($ability, $result['stale']);
        }
    }
}
