<?php

namespace App\Console\Commands;

use App\Domain\Booking\Enums\ArrivalRequestStatus;
use App\Domain\Booking\Events\ArrivalRequestResolved;
use App\Domain\Booking\Events\CoworkingStartExpired;
use App\Domain\Booking\Models\ArrivalRequest;
use App\Domain\Settings\Services\SettingService;
use App\Services\Realtime\RealtimeBroadcaster;
use Illuminate\Console\Command;

/**
 * Mirrors CloseOverdueReceptionSessions's pattern: a pending arrival request
 * older than the configured window almost always means the member scanned
 * and then left without reception acting on it — this keeps the reception
 * queue from accumulating stale entries (docs/decisions/kiosk-display.md).
 *
 * One row at a time, each update conditional on the row still being
 * pending, so a request reception confirms while the sweep runs is never
 * turned into `expired` — and only a request this run actually expired is
 * announced: to the desk, and to a member still waiting on a coworking
 * start. A handful of rows every few minutes, so the per-row cost is
 * nothing.
 */
class ExpireStaleArrivalRequests extends Command
{
    protected $signature = 'kiosk:expire-stale-arrival-requests';

    protected $description = 'Mark pending arrival requests older than the configured window as expired.';

    public function handle(SettingService $settings, RealtimeBroadcaster $realtime): int
    {
        $minutes = (int) $settings->get('kiosk.arrival_request_expiry_minutes', 30);

        ArrivalRequest::query()
            ->where('status', ArrivalRequestStatus::Pending)
            ->where('requested_at', '<', now()->subMinutes($minutes))
            // By id, not offset: each row leaves the filter as it expires.
            ->lazyById()
            ->each(function (ArrivalRequest $request) use ($realtime) {
                $expired = ArrivalRequest::query()
                    ->whereKey($request->id)
                    ->where('status', ArrivalRequestStatus::Pending->value)
                    ->update(['status' => ArrivalRequestStatus::Expired->value]);

                if ($expired === 0) {
                    return;
                }

                if ($request->isCoworkingStart()) {
                    $realtime->send(new CoworkingStartExpired($request));
                }

                $realtime->send(new ArrivalRequestResolved($request, ArrivalRequestStatus::Expired));
            });

        return self::SUCCESS;
    }
}
