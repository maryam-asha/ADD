<?php

namespace Add\Hr\Core\Console;

use Add\Hr\Authorization\Abilities;
use Add\Hr\Contracts\HrNotifier;
use Add\Hr\Core\Services\AlertService;
use Illuminate\Console\Command;

/**
 * The daily digest: one notification to everyone who may read the alerts,
 * saying how many are open. Nothing is sent on a day without alerts.
 */
class SendAlerts extends Command
{
    protected $signature = 'hr:alerts';

    protected $description = 'Notify HR of open alerts (contracts, probations, documents)';

    public function handle(AlertService $alerts, HrNotifier $notifier): int
    {
        $count = count($alerts->current());

        if ($count > 0) {
            $notifier->notifyHolders(Abilities::ALERTS_VIEW, 'alerts_digest', ['count' => $count]);
        }

        $this->info("{$count} open HR alerts.");

        return self::SUCCESS;
    }
}
