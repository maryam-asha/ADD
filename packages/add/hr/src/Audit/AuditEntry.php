<?php

namespace Add\Hr\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One row of the HR audit trail. Append-only: written through HrAudit,
 * never updated.
 */
class AuditEntry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'hr_audit_entries';

    protected $fillable = [
        'actor_id',
        'action',
        'subject_type',
        'subject_id',
        'properties',
    ];

    protected $casts = [
        'properties' => 'array',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
