<?php

namespace App\Console\Commands;

use App\Domain\Access\Services\PasscodeIssuanceService;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Models\Space;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RevokeAccessGrantsOnMaintenance extends Command
{
    protected $signature = 'access:revoke-grants-on-maintenance';

    protected $description = 'Revoke every issued/activated access grant for a lock on any space currently under maintenance.';

    public function handle(PasscodeIssuanceService $issuance): int
    {
        Space::query()
            ->where('status', OperationalStatus::Maintenance)
            ->whereHas('devices', fn ($q) => $q->where('type', 'lock'))
            ->chunkById(100, function ($spaces) use ($issuance) {
                foreach ($spaces as $space) {
                    try {
                        $issuance->revokeForSpace($space);
                    } catch (\Throwable $e) {
                        Log::error('Failed to revoke access grants for a space in maintenance', [
                            'space_id' => $space->id, 'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return self::SUCCESS;
    }
}
