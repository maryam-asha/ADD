<?php

namespace Database\Seeders;

use App\Services\Permissions\PermissionSyncService;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Derives permissions from routes/controllers via PermissionSyncService
     * (the same logic `permissions:sync` runs) and re-attaches every
     * permission to `admin`. Deliberately does NOT grant `operations` any
     * pilot-parity permissions here — that grant belongs to a later task,
     * once branches permissions actually exist to grant.
     */
    public function run(): void
    {
        app(PermissionSyncService::class)->sync();
    }
}
