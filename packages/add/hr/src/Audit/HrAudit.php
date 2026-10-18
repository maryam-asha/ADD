<?php

namespace Add\Hr\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * The HR audit trail: who viewed a document or a sensitive field, who
 * changed an employment, who exported what. Its own table rather than a
 * host's activity log, so the package records the same facts in any host.
 * Call it inside the transaction that makes the change it describes.
 */
class HrAudit
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public static function record(string $action, ?Model $subject = null, array $properties = []): AuditEntry
    {
        return AuditEntry::query()->create([
            'actor_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'properties' => $properties ?: null,
        ]);
    }
}
