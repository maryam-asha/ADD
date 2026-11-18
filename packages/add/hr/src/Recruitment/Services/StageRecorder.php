<?php

namespace Add\Hr\Recruitment\Services;

use Add\Hr\Recruitment\Models\Application;

/**
 * Moves an application to a stage and writes the move to its history.
 * Callers check that the move is allowed and hold the application's lock.
 */
class StageRecorder
{
    public function move(Application $application, string $to, ?string $note, int|string|null $actorId): void
    {
        $from = $application->stage;

        $application->update(['stage' => $to, 'stage_changed_at' => now()]);
        $application->stageChanges()->create([
            'from_stage' => $from,
            'to_stage' => $to,
            'note' => $note === null ? null : mb_substr($note, 0, 500),
            'changed_by' => $actorId,
        ]);
        $application->candidate()->update(['last_activity_at' => now()]);
    }
}
