<?php

namespace App\Console\Commands;

use App\Services\Permissions\PermissionSyncService;
use Illuminate\Console\Command;

class SyncPermissionsCommand extends Command
{
    protected $signature = 'permissions:sync';

    protected $description = 'Derive admin-dashboard permissions from registered routes/controllers, sync them into the permissions table, and re-attach every permission to the admin role.';

    public function handle(PermissionSyncService $service): int
    {
        $result = $service->sync();

        $this->components->info("{$result['total']} permissions in sync (".count($result['created']).' newly created).');

        if ($result['created']) {
            $this->table(['Created'], array_map(fn ($n) => [$n], $result['created']));
        }

        if ($result['stale']) {
            $this->components->warn('No longer matched by a live admin route (left in place, not deleted):');
            $this->table(['Stale'], array_map(fn ($n) => [$n], $result['stale']));
        }

        if ($result['uncovered']) {
            $this->components->warn('Admin controllers with NO permission coverage at all (neither reflected nor manually registered — see PermissionSyncService::MANUAL_REGISTRATIONS docblock):');
            $this->table(['Uncovered controller'], array_map(fn ($n) => [$n], $result['uncovered']));
        }

        return self::SUCCESS;
    }
}
