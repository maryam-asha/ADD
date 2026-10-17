<?php

namespace Add\Hr\Time\Models;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Time\Enums\PunchSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One raw clocking. Never edited: a wrong punch is answered by an HR
 * correction, never by changing the row.
 */
class Punch extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'hr_attendance_punches';

    protected $fillable = [
        'employee_id',
        'punched_at',
        'source',
        'device_type',
        'device_id',
        'idempotency_key',
        'reason',
        'recorded_by',
    ];

    protected $casts = [
        'punched_at' => 'immutable_datetime',
        'source' => PunchSource::class,
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
