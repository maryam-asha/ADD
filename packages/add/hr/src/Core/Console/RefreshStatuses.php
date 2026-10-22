<?php

namespace Add\Hr\Core\Console;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Services\EmploymentLifecycleService;
use Add\Hr\Support\LocalDay;
use Illuminate\Console\Command;

/**
 * Daily: revoke access for every employment whose last day has passed
 * (once — access_revoked_at), then bring every cached status up to today.
 * Safe to run any number of times.
 */
class RefreshStatuses extends Command
{
    protected $signature = 'hr:refresh-statuses';

    protected $description = 'Revoke access after terminations take effect and refresh employee statuses';

    public function handle(EmploymentLifecycleService $lifecycle): int
    {
        Employee::query()
            ->whereNotNull('terminated_on')
            ->where('terminated_on', '<', LocalDay::today()->toDateString())
            ->whereNull('access_revoked_at')
            ->each(fn (Employee $employee) => $lifecycle->revokeAccess($employee));

        Employee::query()->chunkById(200, function ($employees) use ($lifecycle) {
            foreach ($employees as $employee) {
                $lifecycle->refreshStatus($employee);
            }
        });

        return self::SUCCESS;
    }
}
