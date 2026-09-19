<?php

namespace App\Console\Commands;

use App\Domain\Booking\Services\CapacitySlotSynchronizer;
use App\Domain\Foundation\Models\Space;
use Illuminate\Console\Command;

/**
 * Rebuilds `space_capacity_slots` from `bookings`, which is the definition
 * of what that table is supposed to contain.
 *
 * The counter is written inside the same locked transaction as every
 * booking that moves it, so it should never drift. This command exists
 * because "should never" is not a guarantee: a restored backup, a manual
 * SQL fix, or a future writer that forgets the synchronizer would all
 * leave the derived table disagreeing with its source. Keeping the rebuild
 * one command away is what makes that table safe to have at all — see
 * docs/decisions/booking-availability-and-calendar.md.
 *
 * Drift can only ever produce a stale calendar cell, never an overbooking:
 * BookingCreationService decides availability by counting `bookings` under
 * a row lock, not by reading this table.
 */
class ReconcileCapacitySlots extends Command
{
    protected $signature = 'booking:reconcile-capacity-slots
                            {--space= : Limit to one space id}
                            {--dry-run : Report drift without rewriting anything}';

    protected $description = 'Rebuild the derived space_capacity_slots counters from bookings, reporting any drift.';

    public function handle(CapacitySlotSynchronizer $synchronizer): int
    {
        $spaces = Space::query()
            ->when($this->option('space'), fn ($query, $id) => $query->whereKey($id))
            ->get();

        if ($spaces->isEmpty()) {
            $this->warn('No spaces matched.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $driftedSpaces = 0;
        $driftedSlots = 0;

        foreach ($spaces as $space) {
            $drift = $synchronizer->driftFor($space);

            if ($drift->isNotEmpty()) {
                $driftedSpaces++;
                $driftedSlots += $drift->count();

                $this->warn("Space #{$space->id}: {$drift->count()} slot(s) out of step with bookings.");

                foreach ($drift->take(10) as $row) {
                    $this->line("  {$row['slot_start']}  stored={$row['stored']}  expected={$row['expected']}");
                }
            }

            if (! $dryRun) {
                $synchronizer->rebuildFor($space);
            }
        }

        if ($driftedSpaces === 0) {
            $this->info("No drift across {$spaces->count()} space(s).");
        } else {
            $this->warn("Drift found in {$driftedSpaces} space(s), {$driftedSlots} slot(s) total.");
        }

        if ($dryRun) {
            $this->comment('Dry run — nothing was rewritten.');
        } else {
            $this->info("Rebuilt counters for {$spaces->count()} space(s).");
        }

        return self::SUCCESS;
    }
}
