<?php

namespace Add\Hr\Training\Models;

use Add\Hr\Support\Casts\DateOnly;
use Add\Hr\Training\Enums\EnrollmentStatus;
use Add\Hr\Training\Enums\SessionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One run of a course, with a seat limit.
 */
class TrainingSession extends Model
{
    protected $table = 'hr_training_sessions';

    protected $fillable = [
        'course_id',
        'starts_on',
        'ends_on',
        'location',
        'trainer',
        'capacity',
        'self_enrollment',
        'status',
        'created_by',
    ];

    protected $casts = [
        'starts_on' => DateOnly::class,
        'ends_on' => DateOnly::class,
        'capacity' => 'integer',
        'self_enrollment' => 'boolean',
        'status' => SessionStatus::class,
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * Seats taken: every enrollment not cancelled.
     */
    public function seatsTaken(): int
    {
        return $this->enrollments()->where('status', '!=', EnrollmentStatus::Cancelled->value)->count();
    }
}
