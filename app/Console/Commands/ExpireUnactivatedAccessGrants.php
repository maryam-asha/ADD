<?php

namespace App\Console\Commands;

use App\Domain\Access\Services\PasscodeIssuanceService;
use Illuminate\Console\Command;

class ExpireUnactivatedAccessGrants extends Command
{
    protected $signature = 'access:expire-unactivated-grants';

    protected $description = 'Mark any issued access grant past its must_activate_by as expired.';

    public function handle(PasscodeIssuanceService $issuance): int
    {
        $issuance->expireOverdue();

        return self::SUCCESS;
    }
}
