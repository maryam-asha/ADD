<?php

namespace App\Domain\Experience\Services;

use App\Domain\Experience\Models\Event;
use Illuminate\Support\Facades\DB;

/**
 * Keeps an event's envelope in step with its sessions.
 *
 * `events.start_at`/`end_at` are read everywhere — ordering, the upcoming and
 * past filters, the registration deadline, the card, the member app. Rather
 * than teach all of that about sessions, the envelope stays where it is and
 * becomes derived: the earliest start and the latest end.
 *
 * That makes this the single place the two can be written once an event has
 * sessions. Anything else setting them by hand will be overwritten the next
 * time a session changes, which is the intended behaviour — a mismatch
 * between the envelope and the schedule would show one set of hours on the
 * card and another on the detail page.
 *
 * Lives under Domain\Experience\Services and touches nothing outside it, so
 * the layer boundary guard has no objection.
 */
class EventSchedule
{
    /**
     * Replace an event's sessions and re-derive its envelope, atomically.
     *
     * @param  list<array{start_at: string, end_at: string, title?: array|null}>  $sessions
     */
    public function replace(Event $event, array $sessions): void
    {
        DB::transaction(function () use ($event, $sessions) {
            $event->sessions()->delete();

            foreach ($sessions as $session) {
                $event->sessions()->create([
                    'start_at' => $session['start_at'],
                    'end_at' => $session['end_at'],
                    'title' => $session['title'] ?? null,
                ]);
            }

            $this->syncEnvelope($event);
        });
    }

    /**
     * Recompute start_at/end_at from the sessions that now exist.
     *
     * An event with no sessions keeps whatever envelope it has: it is either
     * a single-sitting event created before this existed, or one mid-edit,
     * and blanking its times would drop it out of every listing.
     */
    public function syncEnvelope(Event $event): void
    {
        $bounds = $event->sessions()
            ->selectRaw('MIN(start_at) as first_start, MAX(end_at) as last_end')
            ->first();

        if ($bounds?->first_start === null) {
            return;
        }

        $event->forceFill([
            'start_at' => $bounds->first_start,
            'end_at' => $bounds->last_end,
        ])->save();
    }
}
