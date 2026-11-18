<?php

namespace Add\Hr\Time\Console;

use Add\Hr\Time\Services\ScheduleResolver;
use Add\Hr\Time\Services\TimePeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Rebuilds the last two local days for everyone, so days nobody punched
 * on become `no_record` and late night-shift punches land. Punches rebuild
 * their own days as they arrive; this catches the days without any.
 */
class ProcessAttendance extends Command
{
    protected $signature = 'hr:attendance-process {--from= : first local date (Y-m-d)} {--to= : last local date}';

    protected $description = 'Rebuild attendance days and exceptions';

    public function handle(TimePeriodService $periods, ScheduleResolver $schedule): int
    {
        $today = $schedule->today();
        $from = $this->option('from') ? CarbonImmutable::parse($this->option('from')) : $today->subDays(2);
        $to = $this->option('to') ? CarbonImmutable::parse($this->option('to')) : $today->subDay();

        $count = $periods->rebuild($from, $to);

        $this->info("{$count} attendance days processed.");

        return self::SUCCESS;
    }
}
