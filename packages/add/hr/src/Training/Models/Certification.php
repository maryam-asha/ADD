<?php

namespace Add\Hr\Training\Models;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\Casts\DateOnly;
use Add\Hr\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A certification an employee holds: granted by completing a course, or
 * entered by HR. Its expiry is raised by hr:alerts.
 */
class Certification extends Model
{
    use HasTranslations;

    protected $table = 'hr_certifications';

    protected $fillable = [
        'employee_id',
        'course_id',
        'enrollment_id',
        'name',
        'issuer',
        'issued_on',
        'expires_on',
        'reference',
        'created_by',
    ];

    protected $casts = [
        'name' => 'array',
        'issued_on' => DateOnly::class,
        'expires_on' => DateOnly::class,
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
