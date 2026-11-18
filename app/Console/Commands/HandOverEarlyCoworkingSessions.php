<?php

namespace App\Console\Commands;

use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\CoworkingHandoverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns each early walk-in into the coworking booking it was waiting for,
 * once that booking has started (CoworkingHandoverService). Each one is
 * locked and re-checked there, so a desk checkout in the same instant wins;
 * one that fails is logged and tried again on the next run.
 */
class HandOverEarlyCoworkingSessions extends Command
{
    protected $signature = 'coworking:hand-over-early-sessions';

    protected $description = 'Hand early coworking walk-ins over to the booking they were waiting for once it starts.';

    public function handle(CoworkingHandoverService $handovers): int
    {
        $handedOver = 0;

        WalkinSession::query()
            ->open()
            ->whereNotNull('handover_booking_id')
            ->whereHas('handoverBooking', fn ($query) => $query->where('start_at', '<=', now()))
            ->select('id')
            ->chunkById(100, function ($sessions) use ($handovers, &$handedOver) {
                foreach ($sessions as $session) {
                    try {
                        $handedOver += $handovers->handOver($session) ? 1 : 0;
                    } catch (Throwable $e) {
                        Log::error('[Coworking] could not hand an early session over to its booking', [
                            'walkin_session_id' => $session->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        if ($handedOver > 0) {
            $this->info("Handed {$handedOver} early session(s) over to their booking.");
        }

        return self::SUCCESS;
    }
}
