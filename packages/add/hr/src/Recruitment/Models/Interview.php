<?php

namespace Add\Hr\Recruitment\Models;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Recruitment\Enums\InterviewMode;
use Add\Hr\Recruitment\Enums\InterviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An interview of an application by one or more employees, each of whom
 * scores it on their own scorecard.
 */
class Interview extends Model
{
    protected $table = 'hr_interviews';

    protected $guarded = [];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'duration_minutes' => 'integer',
        'mode' => InterviewMode::class,
        'status' => InterviewStatus::class,
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function interviewers(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'hr_interview_interviewers', 'interview_id', 'employee_id');
    }

    public function scorecards(): HasMany
    {
        return $this->hasMany(InterviewScorecard::class);
    }
}
