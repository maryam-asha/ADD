<?php

namespace Add\Hr\Leave\Console;

use Add\Hr\Leave\Services\LeaveYearEnd;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Applies the carry-over cap to the year that ended and expires carried
 * days past their deadline. Idempotent: run daily.
 */
class CloseLeaveYear extends Command
{
    protected $signature = 'hr:leave-year-end';

    protected $description = 'Cap carried-over leave and expire carried days past their deadline';

    public function handle(LeaveYearEnd $yearEnd): int
    {
        $count = $yearEnd->run(CarbonImmutable::today());

        $this->info("{$count} leave expiries booked.");

        return self::SUCCESS;
    }
}
