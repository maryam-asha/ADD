<?php

namespace Database\Seeders;

use App\Services\Permissions\PermissionSyncService;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Derives permissions from the admin routes' `permission:` middleware via
     * PermissionSyncService (the same logic `permissions:sync` runs) and
     * re-attaches every permission to `admin`.
     *
     * What `operations` holds is not granted here: the migration
     * 2026_10_27_100000_grant_operations_its_pre_permission_reach gives it the
     * reach it had before the admin API stopped checking role names
     * (docs/decisions/rbac-permission-pilot.md, 2026-10-27), and the Roles
     * screen changes it from there. Re-seeding never takes a grant away.
     */
    public function run(): void
    {
        app(PermissionSyncService::class)->sync();
    }
}
