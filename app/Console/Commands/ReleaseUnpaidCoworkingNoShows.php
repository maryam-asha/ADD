<?php

namespace App\Console\Commands;

use App\Domain\Booking\Services\UnpaidNoShowReleaser;
use Illuminate\Console\Command;

/** Frees the seat of each unpaid coworking booking its member has not come to in time (UnpaidNoShowReleaser). */
class ReleaseUnpaidCoworkingNoShows extends Command
{
    protected $signature = 'bookings:release-unpaid-no-shows';

    protected $description = 'Cancel unpaid coworking bookings whose member has not arrived within the release share of their time, and tell them.';

    public function handle(UnpaidNoShowReleaser $releaser): int
    {
        $released = $releaser->run();

        if ($released > 0) {
            $this->info("Released {$released} unpaid coworking booking(s).");
        }

        return self::SUCCESS;
    }
}
