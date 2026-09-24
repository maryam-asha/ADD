<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Gap-free counters for human-readable references ("REQ-2026-00187").
 *
 * Each series is one row in `reference_sequences`. The row is created on
 * first use with insertOrIgnore (so two first-callers can't both insert),
 * then locked and incremented — callers racing on the same series queue
 * behind the lock instead of reading the same value. When called inside an
 * outer transaction the lock is held until that transaction commits, so a
 * rolled-back insert gives its number back rather than leaving a hole.
 */
class ReferenceSequence
{
    public static function next(string $key): int
    {
        return DB::transaction(function () use ($key) {
            DB::table('reference_sequences')->insertOrIgnore(['key' => $key, 'last_value' => 0]);

            $current = DB::table('reference_sequences')
                ->where('key', $key)
                ->lockForUpdate()
                ->value('last_value');

            $next = (int) $current + 1;

            DB::table('reference_sequences')->where('key', $key)->update(['last_value' => $next]);

            return $next;
        });
    }
}
