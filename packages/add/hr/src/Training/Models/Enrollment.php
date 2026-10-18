<?php

namespace Add\Hr\Training\Models;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\Casts\DateOnly;
use Add\Hr\Support\Casts\DecimalString;
use Add\Hr\Training\Enums\EnrollmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Enrollment extends Model
{
    protected $table = 'hr_enrollments';

    protected $fillable = [
        'training_session_id',
        'employee_id',
        'status',
        'completed_on',
        'score',
        'enrolled_by',
    ];

    protected $casts = [
        'status' => EnrollmentStatus::class,
        'completed_on' => DateOnly::class,
        'score' => DecimalString::class,
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'training_session_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
