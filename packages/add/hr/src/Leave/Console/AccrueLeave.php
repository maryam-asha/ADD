<?php

namespace Add\Hr\Leave\Console;

use Add\Hr\Leave\Services\LeaveAccrual;
use Add\Hr\Support\LocalDay;
use Illuminate\Console\Command;

/**
 * Books the monthly accruals and annual grants that are due. Idempotent:
 * run daily, it only adds what is missing.
 */
class AccrueLeave extends Command
{
    protected $signature = 'hr:leave-accrue';

    protected $description = 'Credit leave accruals and annual grants that are due';

    public function handle(LeaveAccrual $accrual): int
    {
        $count = $accrual->run(LocalDay::today());

        $this->info("{$count} leave credits booked.");

        return self::SUCCESS;
    }
}
