<?php

namespace App\Console\Commands;

use App\Domain\Booking\Enums\ArrivalRequestStatus;
use App\Domain\Booking\Models\ArrivalRequest;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Console\Command;

/**
 * Mirrors CloseOverdueReceptionSessions's pattern: a pending arrival request
 * older than the configured window almost always means the member scanned
 * and then left without reception acting on it — this keeps the reception
 * queue from accumulating stale entries (docs/decisions/kiosk-display.md).
 */
class ExpireStaleArrivalRequests extends Command
{
    protected $signature = 'kiosk:expire-stale-arrival-requests';

    protected $description = 'Mark pending arrival requests older than the configured window as expired.';

    public function handle(SettingService $settings): int
    {
        $minutes = (int) $settings->get('kiosk.arrival_request_expiry_minutes', 30);

        ArrivalRequest::query()
            ->where('status', ArrivalRequestStatus::Pending)
            ->where('requested_at', '<', now()->subMinutes($minutes))
            ->update(['status' => ArrivalRequestStatus::Expired->value]);

        return self::SUCCESS;
    }
}
