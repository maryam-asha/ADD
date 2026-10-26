<?php

namespace App\Services\Realtime;

use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one way the app sends a real-time update (App\Domain\Booking\Events).
 * Two promises the plain event() call does not keep:
 *
 * - Sent only once the surrounding transaction commits. A client that hears
 *   the event re-fetches at once, so it must find the change already there;
 *   and a rolled-back change must not be announced at all. Outside a
 *   transaction it is sent straight away.
 * - Never fails the request. The events are ShouldBroadcastNow — the host
 *   runs no queue worker — so the call to the socket server happens inside
 *   the HTTP request, after the data is saved. A socket server that is down,
 *   slow or misconfigured is logged and the request carries on; clients
 *   still have the FCM push and their own polling to fall back on.
 */
class RealtimeBroadcaster
{
    public function send(ShouldBroadcastNow ...$events): void
    {
        DB::afterCommit(function () use ($events) {
            foreach ($events as $event) {
                try {
                    event($event);
                } catch (Throwable $e) {
                    Log::warning('[Realtime] broadcast failed', [
                        'event' => method_exists($event, 'broadcastAs') ? $event->broadcastAs() : $event::class,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }
}
