<?php

namespace App\Console\Commands;

use App\Domain\Booking\Services\GuestVisitService;
use Illuminate\Console\Command;

class RunGuestVisits extends Command
{
    protected $signature = 'guests:run';

    protected $description = 'End coworking guest visits at closing time, remind hosts, and bill visits past their grace.';

    public function handle(GuestVisitService $visits): int
    {
        $visits->sweep();

        return self::SUCCESS;
    }
}
