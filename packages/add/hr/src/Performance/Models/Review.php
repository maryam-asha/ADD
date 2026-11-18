<?php

namespace Add\Hr\Performance\Models;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Performance\Enums\GoalStatus;
use Add\Hr\Performance\Enums\ReviewRecommendation;
use Add\Hr\Performance\Enums\ReviewStatus;
use Add\Hr\Support\Casts\DecimalString;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One employee's review in a cycle: their self review, their reviewer's
 * ratings and the scores computed from them, and HR's calibration.
 */
class Review extends Model
{
    protected $table = 'hr_reviews';

    protected $fillable = [
        'review_cycle_id',
        'employee_id',
        'reviewer_id',
        'status',
        'self_comment',
        'self_submitted_at',
        'manager_comment',
        'manager_submitted_at',
        'recommendation',
        'competency_score',
        'goal_score',
        'overall_score',
        'calibrated_score',
        'calibration_note',
        'final_rating',
        'finalized_at',
    ];

    protected $casts = [
        'status' => ReviewStatus::class,
        'recommendation' => ReviewRecommendation::class,
        'self_submitted_at' => 'datetime',
        'manager_submitted_at' => 'datetime',
        'competency_score' => DecimalString::class,
        'goal_score' => DecimalString::class,
        'overall_score' => DecimalString::class,
        'calibrated_score' => DecimalString::class,
        'final_rating' => 'integer',
        'finalized_at' => 'datetime',
    ];

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ReviewCycle::class, 'review_cycle_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewer_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ReviewAnswer::class)->orderBy('id');
    }

    /**
     * The goals this review rates: the employee's in the cycle, not cancelled.
     *
     * @return Collection<int, Goal>
     */
    public function ratedGoals(): Collection
    {
        return Goal::query()
            ->where('employee_id', $this->employee_id)
            ->where('review_cycle_id', $this->review_cycle_id)
            ->where('status', '!=', GoalStatus::Cancelled->value)
            ->orderBy('id')
            ->get();
    }
}
