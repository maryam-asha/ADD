<?php

namespace Add\Hr\Training\Models;

use Add\Hr\Support\Casts\DecimalString;
use Add\Hr\Training\Enums\BudgetEntryKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement of an employee's learning budget for a year.
 */
class BudgetEntry extends Model
{
    protected $table = 'hr_learning_budget_entries';

    protected $fillable = [
        'employee_id',
        'year',
        'kind',
        'amount',
        'currency',
        'learning_request_id',
        'note',
        'created_by',
    ];

    protected $casts = [
        'year' => 'integer',
        'kind' => BudgetEntryKind::class,
        'amount' => DecimalString::class,
    ];

    public function learningRequest(): BelongsTo
    {
        return $this->belongsTo(LearningRequest::class);
    }
}
