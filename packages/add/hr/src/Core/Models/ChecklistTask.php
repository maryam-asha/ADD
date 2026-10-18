<?php

namespace Add\Hr\Core\Models;

use Add\Hr\Core\Enums\ChecklistKind;
use Add\Hr\Support\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One onboarding or offboarding step for one employee.
 */
class ChecklistTask extends Model
{
    protected $table = 'hr_checklist_tasks';

    protected $fillable = [
        'employee_id',
        'kind',
        'event_id',
        'title',
        'due_on',
        'completed_at',
        'completed_by',
        'notes',
        'sort',
    ];

    protected $casts = [
        'kind' => ChecklistKind::class,
        'title' => 'array',
        'due_on' => DateOnly::class,
        'completed_at' => 'datetime',
        'sort' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
