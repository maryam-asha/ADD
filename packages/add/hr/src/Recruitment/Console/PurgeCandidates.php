<?php

namespace Add\Hr\Recruitment\Console;

use Add\Hr\Recruitment\Services\CandidatePurge;
use Illuminate\Console\Command;

/**
 * Deletes candidates past hr.recruitment.retention_months who were never
 * hired, with their CVs.
 */
class PurgeCandidates extends Command
{
    protected $signature = 'hr:recruitment-purge';

    protected $description = 'Delete candidates kept past the retention period';

    public function handle(CandidatePurge $purge): int
    {
        $this->info($purge->run().' candidates deleted.');

        return self::SUCCESS;
    }
}
