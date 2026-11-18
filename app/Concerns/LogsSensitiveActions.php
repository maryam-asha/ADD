<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Every sensitive action (code issuance, door open, permission change,
 * maintenance assignment — PRD §5.1, and by extension company creation and
 * door-access toggling in Phase 2) is written to spatie/activitylog's
 * `activity_log` table (ERD v2.0's `audit_logs`), with the actor and a
 * before/after payload — not relying on automatic attribute diffing, which
 * can't see a role sync or a pivot-table toggle.
 */
trait LogsSensitiveActions
{
    protected function logSensitiveAction(string $action, Model $subject, array $properties = []): void
    {
        activity()
            ->causedBy(auth()->user())
            ->performedOn($subject)
            ->withProperties($properties)
            ->log($action);
    }
}
