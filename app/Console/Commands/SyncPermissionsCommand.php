<?php

namespace App\Console\Commands;

use App\Services\Permissions\PermissionSyncService;
use Illuminate\Console\Command;

class SyncPermissionsCommand extends Command
{
    protected $signature = 'permissions:sync
        {--prune : Delete the permissions no admin route or permission source names any more (and every grant of them)}';

    protected $description = 'Sync the permissions table with the permission: middleware of the admin routes (plus the HR/Inventory permission sources), and re-attach every permission to the admin role.';

    public function handle(PermissionSyncService $service): int
    {
        $prune = (bool) $this->option('prune');
        $result = $service->sync($prune);

        $this->components->info("{$result['total']} permissions in sync (".count($result['created']).' newly created).');

        if ($result['created']) {
            $this->table(['Created'], array_map(fn ($n) => [$n], $result['created']));
        }

        if ($result['stale']) {
            $prune
                ? $this->components->warn('No longer named by any admin route or permission source — deleted, with every grant of them:')
                : $this->components->warn('No longer named by any admin route or permission source (left in place; run with --prune to delete them):');
            $this->table(['Stale'], array_map(fn ($n) => [$n], $result['stale']));
        }

        return self::SUCCESS;
    }
}
