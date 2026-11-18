<?php

namespace Add\Hr\Core\Models;

use Add\Hr\Core\Enums\EmploymentEventType;
use Add\Hr\Support\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of a career: hire, promotion, termination, ... Written only by
 * EmploymentLifecycleService, never edited.
 */
class EmploymentEvent extends Model
{
    protected $table = 'hr_employment_events';

    protected $fillable = [
        'employee_id',
        'type',
        'effective_on',
        'effective_to',
        'reason',
        'notes',
        'payload',
        'recorded_by',
    ];

    protected $casts = [
        'type' => EmploymentEventType::class,
        'effective_on' => DateOnly::class,
        'effective_to' => DateOnly::class,
        'payload' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
