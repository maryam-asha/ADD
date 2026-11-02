<?php

namespace App\Console\Commands;

use App\Domain\Ecosystem\Models\EventAttendee;
use App\Services\Experience\EventNotifier;
use App\Support\Enums\EventRegistrationStatus;
use Illuminate\Console\Command;

/**
 * A pending registration holds a seat but costs nothing, because points are
 * only taken on approval. Left alone, abandoned applications quietly fill a
 * paid event — and several of them are a cheap way to fill one without ever
 * paying. This closes out anything still undecided by its deadline, freeing
 * the seats while there is still time to re-open them, and tells each member
 * their registration was not accepted.
 *
 * The deadline is set at registration from the event's start
 * (config/events.php), so this only has to find rows whose time has passed.
 */
class ExpirePendingEventRegistrations extends Command
{
    protected $signature = 'events:expire-pending-registrations';

    protected $description = 'Close out event registrations left undecided past their deadline.';

    public function handle(EventNotifier $notices): int
    {
        $expired = 0;

        $undecided = EventAttendee::query()
            ->where('status', EventRegistrationStatus::Pending)
            ->whereNotNull('pending_expires_at')
            ->where('pending_expires_at', '<', now())
            ->with(['user', 'event'])
            ->get();

        foreach ($undecided as $attendee) {
            // Still pending as it is closed: one decided meanwhile keeps its
            // decision and is not told otherwise.
            $closed = EventAttendee::query()
                ->whereKey($attendee->getKey())
                ->where('status', EventRegistrationStatus::Pending)
                ->update([
                    'status' => EventRegistrationStatus::Rejected->value,
                    'rejection_reason' => __('api.event.pending_expired'),
                    // No approved_by: nobody decided this, the deadline did.
                    'approved_at' => now(),
                ]);

            if ($closed === 1) {
                $expired++;
                $notices->rejected($attendee);
            }
        }

        if ($expired > 0) {
            $this->info("Closed out {$expired} undecided registration(s).");
        }

        return self::SUCCESS;
    }
}
