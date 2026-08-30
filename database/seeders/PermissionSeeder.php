<?php

namespace Database\Seeders;

use App\Services\Permissions\PermissionSyncService;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class PermissionSeeder extends Seeder
{
    /**
     * Derives permissions from routes/controllers via PermissionSyncService
     * (the same logic `permissions:sync` runs) and re-attaches every
     * permission to `admin`.
     *
     * Then grants `operations` the Branches permissions it already had
     * before Task B2 switched that resource's routes from the coarse
     * role:admin|operations check to granular `permission:` middleware —
     * this is not optional polish, it's what stops `operations` from
     * silently losing access it currently has. `branches.delete` is
     * deliberately excluded: that action was role:admin-only before this
     * task too, so `operations` never had it.
     */
    public function run(): void
    {
        app(PermissionSyncService::class)->sync();

        Role::findOrCreate('operations', 'web')->givePermissionTo([
            'branches.view', 'branches.create', 'branches.update',
        ]);
    }
}
