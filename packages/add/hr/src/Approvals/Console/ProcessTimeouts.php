<?php

namespace Add\Hr\Approvals\Console;

use Add\Hr\Approvals\Services\ApprovalEngine;
use Illuminate\Console\Command;

/**
 * Applies each overdue step's timeout action: escalate, approve or reject.
 */
class ProcessTimeouts extends Command
{
    protected $signature = 'hr:approvals-timeouts';

    protected $description = 'Escalate, approve or reject approval steps past their deadline';

    public function handle(ApprovalEngine $engine): int
    {
        $count = $engine->processTimeouts();

        $this->info("{$count} overdue approval steps processed.");

        return self::SUCCESS;
    }
}
