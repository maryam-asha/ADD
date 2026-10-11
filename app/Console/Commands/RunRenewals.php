<?php

namespace App\Console\Commands;

use App\Domain\Renewals\Services\RenewalRunner;
use Illuminate\Console\Command;

class RunRenewals extends Command
{
    protected $signature = 'renewals:run';

    protected $description = 'Renew subscriptions and extend leases that auto-renew, and grant started renewals their balances.';

    public function handle(RenewalRunner $runner): int
    {
        $result = $runner->run();

        $this->info(sprintf(
            'Subscriptions renewed: %d (stopped %d), leases extended: %d (stopped %d), balances granted: %d',
            $result['memberships_renewed'],
            $result['memberships_stopped'],
            $result['leases_extended'],
            $result['leases_stopped'],
            $result['balances_granted'],
        ));

        return self::SUCCESS;
    }
}
