<?php

namespace Add\Hr\Benefits\Console;

use Add\Hr\Benefits\Services\BenefitGranter;
use Add\Hr\Contracts\HrClock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Grants the benefits due this month (local time), or in --month=YYYY-MM.
 * Idempotent: run daily, it only adds what is missing, and retries no
 * failed delivery by itself — HR retries once the cause is fixed.
 */
class GrantBenefits extends Command
{
    protected $signature = 'hr:benefits-grant {--month= : YYYY-MM, default the current month}';

    protected $description = 'Grant the benefits due in a month';

    public function handle(BenefitGranter $granter, HrClock $clock): int
    {
        $option = $this->option('month');

        if ($option !== null && ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $option)) {
            $this->error('--month must be YYYY-MM.');

            return self::INVALID;
        }

        $month = $option !== null
            ? CarbonImmutable::createFromFormat('!Y-m', $option, $clock->timezone())
            : CarbonImmutable::now($clock->timezone())->startOfMonth();

        $result = $granter->run($month);

        $this->info("{$result['granted']} benefits granted, {$result['fulfilled']} delivered, {$result['failed']} failed.");

        return self::SUCCESS;
    }
}
