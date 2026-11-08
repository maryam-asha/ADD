<?php

namespace App\Console\Commands;

use App\Domain\Access\Services\AccessDeviceSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Lock and gateway status and lock battery from the TTLock account
 * (AccessDeviceSync). Skipped quietly where no TTLock account is configured
 * (local, tests).
 */
class SyncAccessDevices extends Command
{
    protected $signature = 'access:sync-devices';

    protected $description = 'Refresh lock and gateway status and lock battery from the TTLock account.';

    public function handle(AccessDeviceSync $sync): int
    {
        if (! $sync->configured()) {
            return self::SUCCESS;
        }

        try {
            $sync->sync();
        } catch (\Throwable $e) {
            Log::error('[Access] TTLock device sync failed', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
