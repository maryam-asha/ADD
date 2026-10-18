<?php

namespace Add\Hr\Performance\Models;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Performance\Enums\GoalStatus;
use Add\Hr\Support\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something an employee is to achieve, weighted against their other goals
 * in the same cycle and rated in its review.
 */
class Goal extends Model
{
    protected $table = 'hr_goals';

    protected $fillable = [
        'employee_id',
        'review_cycle_id',
        'title',
        'description',
        'metric',
        'target',
        'weight',
        'progress',
        'status',
        'due_on',
        'created_by',
    ];

    protected $casts = [
        'weight' => 'integer',
        'progress' => 'integer',
        'status' => GoalStatus::class,
        'due_on' => DateOnly::class,
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ReviewCycle::class, 'review_cycle_id');
    }
}
