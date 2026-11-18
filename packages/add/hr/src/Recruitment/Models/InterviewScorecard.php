<?php

namespace Add\Hr\Recruitment\Models;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Recruitment\Enums\Recommendation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One interviewer's verdict: a recommendation, ratings by criterion (1-5)
 * and notes.
 */
class InterviewScorecard extends Model
{
    protected $table = 'hr_interview_scorecards';

    protected $fillable = [
        'interview_id',
        'employee_id',
        'recommendation',
        'ratings',
        'notes',
        'submitted_at',
    ];

    protected $casts = [
        'recommendation' => Recommendation::class,
        'ratings' => 'array',
        'submitted_at' => 'datetime',
    ];

    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }

    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
