<?php

namespace App\Console\Commands;

use App\Domain\Experience\Models\Event;
use App\Domain\Experience\Services\EventSchedule;
use App\Support\Time\LocalTime;
use Illuminate\Console\Command;

/**
 * Repairs schedules the sessions backfill flattened.
 *
 * 2026_09_16_100000 copied every event's envelope into one session, so a
 * four-day course running 10:00–14:00 became a single 76-hour sitting — on
 * the detail page, in the schedule, and in every check that reads sessions.
 * This splits such an event back into one sitting a day
 * (EventSchedule::sittingsFromEnvelope), and gives an event with no sessions
 * at all the one its envelope describes.
 *
 * Only an event with exactly one session whose split would differ is
 * touched: one already scheduled by day, or a genuinely continuous run
 * (ending at or before the hour it started), is left as it is. Lists what it
 * would change unless --apply is given.
 */
class RepairEventSessions extends Command
{
    protected $signature = 'events:repair-sessions {--apply : Write the changes instead of only listing them}';

    protected $description = 'Split multi-day events flattened into one session back into daily sittings.';

    public function handle(EventSchedule $schedule, LocalTime $time): int
    {
        $apply = (bool) $this->option('apply');
        $changed = 0;

        Event::query()->withCount('sessions')->orderBy('id')->each(function (Event $event) use ($schedule, $time, $apply, &$changed) {
            if ($event->sessions_count > 1) {
                return;
            }

            $sittings = $schedule->sittingsFromEnvelope($event);

            if ($sittings === [] || ($event->sessions_count === 1 && count($sittings) === 1)) {
                return;
            }

            $local = fn (string $utc) => $time->toLocal($utc)->format('Y-m-d H:i');
            $this->line(sprintf(
                '#%d %s: %s → %d sitting(s), %s – %s',
                $event->id,
                $event->translate('title', 'en') ?? '',
                $event->sessions_count === 0 ? 'no sessions' : '1 session',
                count($sittings),
                $local($sittings[0]['start_at']).'–'.substr($local($sittings[0]['end_at']), 11),
                $local(end($sittings)['start_at']).'–'.substr($local(end($sittings)['end_at']), 11),
            ));

            if ($apply) {
                $schedule->replace($event, $sittings);
            }

            $changed++;
        });

        if ($changed === 0) {
            $this->info('Nothing to repair.');
        } elseif ($apply) {
            $this->info("Repaired {$changed} event(s).");
        } else {
            $this->warn("{$changed} event(s) would change. Re-run with --apply to write them.");
        }

        return self::SUCCESS;
    }
}
