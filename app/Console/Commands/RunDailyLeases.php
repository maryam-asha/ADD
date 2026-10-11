<?php

namespace App\Console\Commands;

use App\Domain\Leasing\Services\LeaseDailyRunner;
use Illuminate\Console\Command;

class RunDailyLeases extends Command
{
    protected $signature = 'leases:run-daily';

    protected $description = 'Issue due office-lease invoices, open and close office doors, and end expired leases.';

    public function handle(LeaseDailyRunner $runner): int
    {
        $result = $runner->run();

        $this->info(sprintf(
            'Invoices issued: %d, doors opened: %d, leases ended: %d, doors closed: %d',
            $result['invoices'],
            $result['doors_opened'],
            $result['ended'],
            $result['doors_closed'],
        ));

        return self::SUCCESS;
    }
}
